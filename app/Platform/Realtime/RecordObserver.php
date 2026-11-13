<?php

declare(strict_types=1);

namespace App\Platform\Realtime;

use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns a write to one of {@see RecordTopics::MODELS} into a live {@see Change} for the
 * environment, organization and person it belongs to.
 *
 * What a change is ABOUT is read from the row's own columns — `environment_id`,
 * `organization_id`, `user_id`/`subject_id` — never from the request that made it, so a
 * job, a console command and an API call are live exactly like a page.
 *
 * Nothing here may break a write: every path is wrapped and reported.
 */
final class RecordObserver
{
    public function __construct(private readonly LiveUpdates $live) {}

    public function created(Model $model): void
    {
        $this->observe($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->observe($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->observe($model, 'deleted');
    }

    private function observe(Model $model, string $event): void
    {
        if (! LiveUpdates::enabled()) {
            return;
        }

        try {
            $definition = RecordTopics::MODELS[$model::class] ?? null;

            if ($definition === null || ! in_array($event, $definition['events'] ?? ['created', 'updated', 'deleted'], true)) {
                return;
            }

            if ($event === 'updated' && ! self::newsworthy($model)) {
                return;
            }

            $row = $model->getAttributes();
            $action = $event === 'updated' && $model->wasChanged('revoked_at') && ($row['revoked_at'] ?? null) !== null
                ? 'revoked'
                : $event;

            $key = $model->getKey();
            $id = is_scalar($key) ? (string) $key : null;
            $audience = $this->audience($model, $row);
            $connection = $model->getConnection();

            $topics = $model instanceof AuditEntry
                ? AuditTopics::for(self::string($row['action'] ?? null) ?? '')
                : [$definition['topic']];

            foreach ($topics as $topic) {
                $this->live->record(new Change($topic, $definition['type'], $id, $action), $audience, $connection);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Whether an update touched anything beyond activity stamps and bookkeeping. */
    private static function newsworthy(Model $model): bool
    {
        return array_diff(array_keys($model->getChanges()), RecordTopics::QUIET) !== [];
    }

    /**
     * Read from the raw row, not through accessors: a model that has no such column must
     * answer null here, never throw under strict attribute access.
     *
     * @param  array<string, mixed>  $row
     */
    private function audience(Model $model, array $row): Audience
    {
        $environment = self::string($row['environment_id'] ?? null);

        return match (true) {
            $model instanceof User => new Audience($environment, [], self::strings($model->getKey())),
            $model instanceof Organization => new Audience($environment, self::strings($model->getKey())),
            $model instanceof AuditEntry => new Audience(
                $environment,
                self::strings($row['organization_id'] ?? null),
                self::strings(
                    ($row['actor_type'] ?? null) === ActorType::User->value ? $row['actor_id'] ?? null : null,
                    in_array($row['target_type'] ?? null, ['user', 'subject'], true) ? $row['target_id'] ?? null : null,
                ),
            ),
            // A project is a root organization's; its environments are listed on that
            // organization's console.
            $model instanceof Project => new Audience(null, self::strings($row['organization_id'] ?? null)),
            $model instanceof Environment => new Audience(
                self::string($model->getKey()),
                self::strings(self::projectOrganization(self::string($row['project_id'] ?? null))),
            ),
            default => new Audience(
                $environment,
                self::strings($row['organization_id'] ?? null),
                self::strings($row['user_id'] ?? $row['subject_id'] ?? null),
            ),
        };
    }

    private static function projectOrganization(?string $projectId): ?string
    {
        if ($projectId === null) {
            return null;
        }

        // The projects table is not environment-scoped; a plain read, no model scopes.
        return self::string(DB::table((new Project)->getTable())->where('id', $projectId)->value('organization_id'));
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function strings(mixed ...$values): array
    {
        return array_values(array_filter(array_map(self::string(...), $values), static fn (?string $v): bool => $v !== null));
    }
}
