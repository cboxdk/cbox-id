<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use App\Actions\AuditLogs\CreateAuditLogEvents;
use App\Models\AuditLogs\AuditLogEvent;
use App\Models\AuditLogs\AuditLogSchema;
use App\Platform\Actions\ActionRefused;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Takes a batch of audit events in: checks every one, then appends them to their
 * organizations' chains and hands them to those organizations' log streams.
 *
 * ALL OR NOTHING. A batch with one bad event is refused whole, with every problem named
 * by its place in the batch (`events.3.metadata.total`) — a partial success would leave a
 * caller retrying a batch half of which is already in, and the Idempotency-Key that makes a
 * retry safe covers the request, not its parts. The action runs in one transaction
 * ({@see CreateAuditLogEvents}), so a failure after the checks leaves nothing behind
 * either.
 */
final readonly class AuditLogIngest
{
    /** How many problems one refusal names before it stops: enough to fix a batch, not a novel. */
    private const int MAX_REPORTED = 25;

    public function __construct(
        private AuditLogChains $chains,
        private AuditLogStreams $streams,
        private AuditLogPolicy $policy,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events  Through the action's input rules.
     * @param  string|null  $confinedTo  The one organization the caller may write for, or null for any in the environment.
     * @return list<AuditLogEvent> In the order they were given.
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     */
    public function record(array $events, ?string $confinedTo): array
    {
        $actions = array_values(array_unique(array_filter(array_map(
            static fn (array $event): mixed => $event['action'] ?? null,
            $events,
        ), is_string(...))));

        /** @var array<string, AuditLogSchema> $schemas */
        $schemas = AuditLogSchema::query()->whereIn('action', $actions)->get()->keyBy('action')->all();
        $strict = $this->policy->strict();

        $normalized = [];
        /** @var array<int, string> $owners batch position => its organization */
        $owners = [];
        $errors = [];

        foreach ($events as $index => $event) {
            $organizationId = is_string($event['organization_id'] ?? null) ? $event['organization_id'] : '';

            // A confined caller — an organization's administrator, a token they signed in
            // for — writes for their own organization only, refused outright like every other
            // reach across organizations.
            if ($confinedTo !== null && $organizationId !== $confinedTo) {
                throw new AuthorizationException('You may only record audit events for your own organization.');
            }

            $action = is_string($event['action'] ?? null) ? $event['action'] : '';
            [$clean, $problems] = EventShape::check($event, "events.{$index}", $schemas[$action] ?? null, $strict);

            $errors = [...$errors, ...$problems];

            if ($clean !== null) {
                $normalized[$index] = $clean;
                $owners[$index] = $organizationId;
            }
        }

        if ($errors !== []) {
            /** @var non-empty-array<string, string> $reported */
            $reported = array_slice($errors, 0, self::MAX_REPORTED, true);

            throw ActionRefused::onFields('invalid_event', $reported);
        }

        $this->assertOrganizationsExist($owners);

        /** @var array<string, list<int>> $byOrganization organization => the batch positions of its events */
        $byOrganization = [];

        foreach ($owners as $index => $organizationId) {
            $byOrganization[$organizationId][] = $index;
        }

        $stored = [];

        foreach ($byOrganization as $organizationId => $positions) {
            $appended = $this->chains->append($organizationId, array_map(static fn (int $index): array => $normalized[$index], $positions));
            $this->streams->dispatch($organizationId, $appended);

            foreach ($positions as $offset => $index) {
                $stored[$index] = $appended[$offset];
            }
        }

        ksort($stored);

        return array_values($stored);
    }

    /**
     * Every organization the batch names is one of THIS environment's — the model is
     * environment-scoped, so an id from anywhere else simply is not found.
     *
     * @param  array<int, string>  $owners  batch position => the organization it names
     *
     * @throws ActionRefused
     */
    private function assertOrganizationsExist(array $owners): void
    {
        $known = [];

        foreach (Organization::query()->whereIn('id', array_values(array_unique($owners)))->pluck('id') as $id) {
            if (is_string($id)) {
                $known[$id] = true;
            }
        }

        $missing = [];

        foreach ($owners as $index => $organizationId) {
            if (! isset($known[$organizationId]) && count($missing) < self::MAX_REPORTED) {
                $missing["events.{$index}.organization_id"] = 'No organization with that organization_id exists in this environment.';
            }
        }

        if ($missing !== []) {
            throw ActionRefused::onFields('organization_not_found', $missing);
        }
    }
}
