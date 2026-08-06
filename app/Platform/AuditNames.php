<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Organizations;
use Illuminate\Support\Collection;

/**
 * Turns the opaque ids on a page of audit entries into names a person recognises.
 *
 * An audit row's whole value is the sentence it tells — "Ada Lovelace added Grace
 * Hopper". Rendered raw, the console instead showed "organization · member added ·
 * user 01kywrnrvv…", which withholds the one fact the reader came for: WHO. The
 * dashboard's activity feed already resolved names; the audit page itself, where
 * someone actually goes to answer that question, did not.
 *
 * Everything is batch-loaded per page (findMany), never per row: this renders 25 rows
 * with at most three queries regardless of how many distinct people appear on them.
 * Ids with no name — a deleted subject, a type we do not resolve — are simply absent,
 * and callers fall back to showing the id.
 */
final readonly class AuditNames
{
    public function __construct(
        private Subjects $subjects,
        private Organizations $organizations,
    ) {}

    /**
     * Display names for every actor and target on these entries, keyed by id.
     *
     * @param  iterable<int, AuditEntry>  $entries
     * @return array<string, string>
     */
    public function for(iterable $entries): array
    {
        /** @var Collection<int, AuditEntry> $rows */
        $rows = $entries instanceof Collection ? $entries : new Collection(iterator_to_array($entries));

        $userIds = [];
        $orgIds = [];
        $clientIds = [];

        foreach ($rows as $entry) {
            // An actor id is a subject id whenever the actor is a person; a service
            // actor's id is a client_id, which has its own (human) name.
            $actorId = $entry->actor_id;
            if (is_string($actorId) && $actorId !== '') {
                match ($entry->actor_type->value) {
                    'user', 'operator', 'organization_member' => $userIds[] = $actorId,
                    'service' => $clientIds[] = $actorId,
                    default => null,
                };
            }

            $targetId = $entry->target_id;
            if (! is_string($targetId) || $targetId === '') {
                continue;
            }

            match ($entry->target_type) {
                'user', 'subject' => $userIds[] = $targetId,
                'organization' => $orgIds[] = $targetId,
                'client' => $clientIds[] = $targetId,
                default => null,
            };
        }

        return [
            ...$this->subjectNames($userIds),
            ...$this->organizationNames($orgIds),
            ...$this->clientNames($clientIds),
        ];
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function subjectNames(array $ids): array
    {
        $names = [];

        foreach ($this->subjects->findMany(array_values(array_unique($ids))) as $id => $subject) {
            $name = $subject->name;
            $label = is_string($name) && trim($name) !== '' ? $name : $subject->email;

            if (is_string($label) && $label !== '') {
                $names[$id] = $label;
            }
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function organizationNames(array $ids): array
    {
        $names = [];

        foreach ($this->organizations->findMany(array_values(array_unique($ids))) as $id => $organization) {
            $names[$id] = $organization->name;
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function clientNames(array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = Client::query()
            ->whereIn('client_id', $ids)
            ->pluck('name', 'client_id')
            ->all();

        return $names;
    }
}
