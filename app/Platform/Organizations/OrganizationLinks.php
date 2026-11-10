<?php

declare(strict_types=1);

namespace App\Platform\Organizations;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Which platform-root organization IS which organization in the `cbox` environment, and
 * so which slug addresses it at `/orgs/{slug}`.
 *
 * A Cbox customer has one organization, and Work, Billing and the rest keep it in the
 * `cbox` environment. Cbox ID's console authority (projects, keys, billing, the team)
 * still hangs off a row in the platform-root environment. The link joins the two, and the
 * `cbox` organization's slug is the address: the same `/orgs/{slug}` in every Cbox app.
 * An unlinked platform-root organization keeps its own slug as its address.
 *
 * Reads go straight to the tables with the environment named in the query, never through
 * the environment-scoped models: this class answers across two environments on purpose,
 * and a global scope would quietly answer for whichever one the request stands on.
 */
final class OrganizationLinks
{
    /** @var array<string, array{slug: string, name: string, linked: bool}> */
    private array $addresses = [];

    private ?string $cboxEnvironment = null;

    private bool $cboxResolved = false;

    /**
     * The `cbox` environment's id, or null where this deployment has none (a fresh
     * install, a developer machine, the test suite). Never the platform root itself.
     */
    public function cboxEnvironmentId(): ?string
    {
        if ($this->cboxResolved) {
            return $this->cboxEnvironment;
        }

        $slug = config('cbox-id.cbox_environment');
        $id = is_string($slug) && $slug !== ''
            ? DB::table('environments')->where('slug', $slug)->where('is_default', false)->value('id')
            : null;

        $this->cboxResolved = true;

        return $this->cboxEnvironment = is_string($id) ? $id : null;
    }

    /**
     * The platform root's id: the `is_default` environment. A deployment without one (a
     * single-environment install, most of the test suite) runs its console in the
     * environment the request stands in, so that is the one whose memberships count.
     */
    public function rootEnvironmentId(): ?string
    {
        $id = DB::table('environments')->where('is_default', true)->value('id');

        if (is_string($id)) {
            return $id;
        }

        $ambient = app(EnvironmentContext::class)->current()?->environmentKey();

        return is_string($ambient) && $ambient !== '' ? $ambient : null;
    }

    /**
     * The slug `/orgs/{slug}` names a platform-root organization by, and the name the
     * console shows for it: the linked `cbox` organization's, or its own.
     *
     * @return array{slug: string, name: string, linked: bool}|null
     */
    public function addressOf(string $organizationId): ?array
    {
        return $this->addressesOf([$organizationId])[$organizationId] ?? null;
    }

    /**
     * @param  list<string>  $organizationIds
     * @return array<string, array{slug: string, name: string, linked: bool}>
     */
    public function addressesOf(array $organizationIds): array
    {
        $missing = array_values(array_diff(array_unique($organizationIds), array_keys($this->addresses)));

        if ($missing !== []) {
            $rows = DB::table('organizations as o')
                ->leftJoin('organization_links as l', 'l.organization_id', '=', 'o.id')
                ->leftJoin('organizations as c', 'c.id', '=', 'l.cbox_organization_id')
                ->whereIn('o.id', $missing)
                ->get(['o.id', 'o.slug', 'o.name', 'c.slug as cbox_slug', 'c.name as cbox_name']);

            // An unlinked organization's own slug that a link already claims for another
            // organization: two organizations never share an address, so it is addressed
            // by its id until an operator links it ({@see candidatesFor()}).
            // Asked only about the unlinked rows on hand, and by their ids.
            $unlinked = $rows->filter(static fn (object $row): bool => ! is_string($row->cbox_slug) || $row->cbox_slug === '')
                ->pluck('id')
                ->all();

            $claimed = $unlinked === [] ? [] : DB::table('organization_links as l')
                ->join('organizations as c', 'c.id', '=', 'l.cbox_organization_id')
                ->whereIn('c.slug', DB::table('organizations')->select('slug')->whereIn('id', $unlinked))
                ->pluck('l.organization_id', 'c.slug')
                ->all();

            foreach ($rows as $row) {
                $linked = is_string($row->cbox_slug) && $row->cbox_slug !== '';
                $own = self::str($row->slug);
                $taken = ! $linked && isset($claimed[$own]) && self::str($claimed[$own]) !== self::str($row->id);

                $this->addresses[self::str($row->id)] = [
                    'slug' => $linked ? self::str($row->cbox_slug) : ($taken ? strtolower(self::str($row->id)) : $own),
                    'name' => $linked ? self::str($row->cbox_name) : self::str($row->name),
                    'linked' => $linked,
                ];
            }
        }

        return array_intersect_key($this->addresses, array_flip($organizationIds));
    }

    /**
     * The platform-root organizations `/orgs/{slug}` may name, linked ones first: a linked
     * organization answers to its `cbox` slug, an unlinked one to its own. A slug that a
     * link claims is never also read as an unlinked organization's own, which is the rule
     * {@see link()} keeps true for new pairs.
     *
     * @return list<string>
     */
    public function candidatesFor(string $slug): array
    {
        $root = $this->rootEnvironmentId();

        if ($root === null || $slug === '') {
            return [];
        }

        $linked = DB::table('organization_links as l')
            ->join('organizations as c', 'c.id', '=', 'l.cbox_organization_id')
            ->where('c.slug', $slug)
            ->pluck('l.organization_id')
            ->map(static fn (mixed $id): string => self::str($id))
            ->all();

        // A slug a link claims names the linked organization and nothing else.
        if ($linked !== []) {
            return array_values(array_unique($linked));
        }

        // Else an unlinked organization by its own slug, or by its id where its slug is
        // claimed ({@see addressesOf()}).
        $own = DB::table('organizations as o')
            ->leftJoin('organization_links as l', 'l.organization_id', '=', 'o.id')
            ->where('o.environment_id', $root)
            ->where(static fn (Builder $query): Builder => $query->where('o.slug', $slug)->orWhere('o.id', $slug))
            ->whereNull('l.id')
            ->pluck('o.id')
            ->map(static fn (mixed $id): string => self::str($id))
            ->all();

        return array_values(array_unique($own));
    }

    /**
     * Pair a platform-root organization with its `cbox` organization.
     *
     * Refused, with the reason, when either side is already paired, when the two ids are
     * not in the environments they claim to be in, or when the `cbox` slug is the address
     * of ANOTHER platform-root organization (its own slug, unlinked): pairing it would give
     * two organizations one address.
     *
     * @throws DomainException
     */
    public function link(string $organizationId, string $cboxOrganizationId, string $pairedBy = 'operator'): void
    {
        $root = $this->rootEnvironmentId();
        $cbox = $this->cboxEnvironmentId();

        if ($root === null || $cbox === null) {
            throw new DomainException('This deployment has no `cbox` environment to link to.');
        }

        $platform = DB::table('organizations')->where('id', $organizationId)->where('environment_id', $root)->first(['id', 'slug']);
        $shared = DB::table('organizations')->where('id', $cboxOrganizationId)->where('environment_id', $cbox)->first(['id', 'slug']);

        if ($platform === null) {
            throw new DomainException("No platform-root organization {$organizationId}.");
        }

        if ($shared === null) {
            throw new DomainException("No organization {$cboxOrganizationId} in the cbox environment.");
        }

        if (DB::table('organization_links')->where('organization_id', $organizationId)->exists()) {
            throw new DomainException('Platform-root organization '.self::str($platform->slug).' is already linked.');
        }

        if (DB::table('organization_links')->where('cbox_organization_id', $cboxOrganizationId)->exists()) {
            throw new DomainException('Cbox organization '.self::str($shared->slug).' is already linked.');
        }

        $taken = DB::table('organizations as o')
            ->leftJoin('organization_links as l', 'l.organization_id', '=', 'o.id')
            ->where('o.environment_id', $root)
            ->where('o.slug', $shared->slug)
            ->where('o.id', '!=', $organizationId)
            ->whereNull('l.id')
            ->exists();

        if ($taken) {
            throw new DomainException('The slug '.self::str($shared->slug).' already addresses another platform-root organization.');
        }

        DB::table('organization_links')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'cbox_organization_id' => $cboxOrganizationId,
            'cbox_environment_id' => $cbox,
            'paired_by' => $pairedBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        unset($this->addresses[$organizationId]);
    }

    /**
     * Pair every unpaired platform-root organization with the `cbox` organization of the
     * same slug. Returns how many it paired. Safe to run again.
     */
    public function pairBySlug(): int
    {
        $root = $this->rootEnvironmentId();
        $cbox = $this->cboxEnvironmentId();

        if ($root === null || $cbox === null) {
            return 0;
        }

        $pairs = DB::table('organizations as o')
            ->join('organizations as c', static function (JoinClause $join) use ($cbox): void {
                $join->on('c.slug', '=', 'o.slug')->where('c.environment_id', '=', $cbox);
            })
            ->leftJoin('organization_links as lo', 'lo.organization_id', '=', 'o.id')
            ->leftJoin('organization_links as lc', 'lc.cbox_organization_id', '=', 'c.id')
            ->where('o.environment_id', $root)
            ->whereNull('lo.id')
            ->whereNull('lc.id')
            ->get(['o.id as organization_id', 'c.id as cbox_organization_id']);

        $paired = 0;

        foreach ($pairs as $pair) {
            try {
                $this->link(self::str($pair->organization_id), self::str($pair->cbox_organization_id), 'slug');
                $paired++;
            } catch (DomainException) {
                // Left for an operator; `orgs:link --unpaired` names it.
            }
        }

        return $paired;
    }

    /**
     * Platform-root organizations with no link, for the operator's report.
     *
     * @return list<array{id: string, slug: string, name: string}>
     */
    public function unpaired(): array
    {
        $root = $this->rootEnvironmentId();

        if ($root === null) {
            return [];
        }

        return array_values(DB::table('organizations as o')
            ->leftJoin('organization_links as l', 'l.organization_id', '=', 'o.id')
            ->where('o.environment_id', $root)
            ->whereNull('l.id')
            ->orderBy('o.slug')
            ->get(['o.id', 'o.slug', 'o.name'])
            ->map(static fn (object $row): array => ['id' => self::str($row->id), 'slug' => self::str($row->slug), 'name' => self::str($row->name)])
            ->values()
            ->all());
    }

    /** Forget what this instance read, after a write elsewhere changed it. */
    public function flush(): void
    {
        $this->addresses = [];
        $this->cboxResolved = false;
        $this->cboxEnvironment = null;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
