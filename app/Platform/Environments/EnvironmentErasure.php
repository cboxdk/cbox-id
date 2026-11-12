<?php

declare(strict_types=1);

namespace App\Platform\Environments;

use App\Platform\AuditLogs\AuditLogExports;
use App\Platform\Realm\FlipSchema;
use App\Platform\Realm\FlipTables;
use Cbox\Id\Organization\EnvironmentResolutionCache;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DELETING AN ENVIRONMENT: its people, apps, keys, sessions, connections and settings, and
 * then the environment itself.
 *
 * DRIVEN BY THE SCHEMA, CHECKED AGAINST A LIST. Every table that carries an environment
 * column is found in the database ({@see FlipSchema}, the reader the realm flip uses), and
 * what happens to it is written down in {@see ErasedTables}. A table that holds rows of the
 * environment and has no decision stops the deletion before anything is removed: a new
 * feature's table is never silently left behind, and never silently emptied either.
 *
 * ONE TRANSACTION. Either the environment and everything in it are gone, or nothing is.
 * The environment row goes last, under a lock, so a second request for the same deletion
 * waits and then finds nothing.
 *
 * THEN ONCE MORE. A sign-in that had already resolved the environment when the deletion
 * started may still write a row (a session, a counter) while the transaction runs; no
 * foreign key stops it. After the commit no host resolves to the environment any more, so
 * the same sweep runs a second time and takes what arrived in between.
 *
 * WHAT STAYS is the platform's own audit trail ({@see ErasedTables::KEEP}) and the
 * organization's log, which is where the deletion itself is recorded.
 */
final readonly class EnvironmentErasure
{
    public function __construct(
        private FlipSchema $schema,
        private PlatformRoot $root,
        private EnvironmentResolutionCache $resolution,
    ) {}

    /**
     * Why this environment is never deleted, or null when it may be.
     *
     * `default`: the platform's own environment, the one every console signs in through.
     * `reserved`: its slug is one of the platform's own hosts.
     * `configured`: the deployment's configuration names it (by id or slug), so something
     * the operator set up depends on it; the answer carries the config key.
     */
    public function protection(Environment $environment): ?string
    {
        if ($environment->is_default === true || $this->root->environment()?->environmentKey() === $environment->id) {
            return 'default';
        }

        if (ReservedEnvironmentSlugs::reserved($environment->slug)) {
            return 'reserved';
        }

        return $this->configuredAs($environment) === null ? null : 'configured';
    }

    /** The configuration key that names this environment, by id or by slug. */
    public function configuredAs(Environment $environment): ?string
    {
        foreach (array_keys(FlipTables::ENV_VARS) as $key) {
            foreach (self::strings(config($key)) as $value) {
                if ($value === $environment->id || $value === $environment->slug) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * What the confirmation shows: how much goes with the environment.
     *
     * @return array{people: int, apps: int, organizations: int}
     */
    public function contents(string $environmentId): array
    {
        return $this->contentsOf([$environmentId])[$environmentId];
    }

    /**
     * {@see self::contents()} for a page of environments, in three queries.
     *
     * @param  list<string>  $environmentIds
     * @return array<string, array{people: int, apps: int, organizations: int}>
     */
    public function contentsOf(array $environmentIds): array
    {
        $contents = [];

        foreach ($environmentIds as $environmentId) {
            $contents[$environmentId] = ['people' => 0, 'apps' => 0, 'organizations' => 0];
        }

        if ($contents === []) {
            return $contents;
        }

        foreach (['people' => 'users', 'apps' => 'oauth_clients', 'organizations' => 'organizations'] as $what => $table) {
            $counted = DB::table($table)
                ->whereIn('environment_id', $environmentIds)
                ->groupBy('environment_id')
                ->selectRaw('environment_id, count(*) as total')
                ->pluck('total', 'environment_id');

            foreach ($counted as $environmentId => $rows) {
                if (is_string($environmentId) && isset($contents[$environmentId]) && is_numeric($rows)) {
                    $contents[$environmentId][$what] = (int) $rows;
                }
            }
        }

        return $contents;
    }

    /**
     * Tables that hold rows of the environment and have no decision in {@see ErasedTables}.
     *
     * @return list<string>
     */
    public function undecided(string $environmentId): array
    {
        $decided = ErasedTables::all();
        $undecided = [];

        foreach ($this->schema->tables() as $table) {
            $column = $this->schema->environmentColumn($table);

            if ($column !== null && ! isset($decided[$table]) && DB::table($table)->where($column, $environmentId)->exists()) {
                $undecided[] = $table;
            }
        }

        return $undecided;
    }

    /**
     * Remove the environment and everything in it.
     *
     * The caller has decided that it MAY ({@see self::protection()}, and whose it is);
     * the protection is asked again here, because this is the one place that deletes.
     *
     * @return array<string, int> rows removed per table, tables with none left out
     *
     * @throws EnvironmentNotErasable
     */
    public function erase(Environment $environment): array
    {
        $id = $environment->id;

        if ($id === '' || ($protection = $this->protection($environment)) !== null) {
            throw EnvironmentNotErasable::protected($protection ?? 'default');
        }

        if (($undecided = $this->undecided($id)) !== []) {
            throw EnvironmentNotErasable::undecided($undecided);
        }

        // Files are not in the transaction; their rows are. Read the paths before the rows go.
        $exports = $this->schema->hasColumn('app_audit_exports', 'path')
            ? DB::table('app_audit_exports')->where('environment_id', $id)->whereNotNull('path')->pluck('path')->filter(static fn (mixed $path): bool => is_string($path) && $path !== '')->values()->all()
            : [];

        $removed = DB::transaction(function () use ($id): array {
            $locked = Environment::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null) {
                return [];
            }

            $removed = $this->sweep($id);

            // Through the model, so the resolution cache forgets its hosts.
            $locked->delete();

            return $removed;
        });

        // Forgotten again now that the deletion is visible to everyone: a request that
        // resolved the environment while the transaction ran may have cached it back.
        $this->forget($environment);

        foreach ($this->sweep($id) as $table => $rows) {
            $removed[$table] = ($removed[$table] ?? 0) + $rows;
        }

        foreach ($exports as $path) {
            try {
                AuditLogExports::disk()->delete((string) $path);
            } catch (Throwable $error) {
                // An export file expires by itself; a storage hiccup does not undo a deletion.
                report($error);
            }
        }

        return $removed;
    }

    /**
     * Delete the environment's rows from every table decided ERASE.
     *
     * @return array<string, int>
     */
    private function sweep(string $id): array
    {
        $decided = ErasedTables::all();
        $removed = [];

        // Children with no environment column of their own, while their parents still say
        // whose they are.
        foreach (ErasedTables::CHILDREN as $table => $parents) {
            foreach ($parents as $column => [$parentTable, $parentColumn]) {
                $parentEnvironment = $this->schema->environmentColumn($parentTable);

                if ($parentEnvironment === null || ! $this->schema->hasColumn($table, $column) || ! $this->schema->hasColumn($parentTable, $parentColumn)) {
                    continue;
                }

                $rows = DB::table($table)
                    ->whereIn($column, DB::table($parentTable)->where($parentEnvironment, $id)->select($parentColumn))
                    ->delete();

                if ($rows > 0) {
                    $removed[$table] = ($removed[$table] ?? 0) + $rows;
                }
            }
        }

        foreach ($this->schema->tables() as $table) {
            $column = $this->schema->environmentColumn($table);

            if ($column === null || ($decided[$table]['decision'] ?? null) !== ErasedTables::ERASE) {
                continue;
            }

            $rows = DB::table($table)->where($column, $id)->delete();

            if ($rows > 0) {
                $removed[$table] = ($removed[$table] ?? 0) + $rows;
            }
        }

        return $removed;
    }

    private function forget(Environment $environment): void
    {
        $this->resolution->forgetEnvironment($environment->id);
        $this->resolution->forgetHost($environment->domain);

        foreach ($this->resolution->baseDomains() as $base) {
            $this->resolution->forgetHost($environment->slug.'.'.$base);
        }
    }

    /**
     * A config value as the strings it holds: one id, a comma-separated list, or an array.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $part): bool => $part !== ''));
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $key => $item) {
            // A map keyed by environment names it as much as a list of them does.
            if (is_string($key) && $key !== '') {
                $strings[] = $key;
            }

            $strings = [...$strings, ...self::strings($item)];
        }

        return $strings;
    }
}
