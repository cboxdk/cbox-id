<?php

declare(strict_types=1);

namespace App\Platform\Organizations;

use Cbox\Id\Organization\Enums\MembershipStatus;
use Illuminate\Support\Facades\DB;

/**
 * The organizations a person may open in Cbox ID's console, addressed the way every
 * Cbox app addresses them: `/orgs/{slug}`.
 *
 * Membership is the platform-root membership, because that is where the console's
 * authority (projects, keys, billing, the team) lives. The slug and the name are the
 * linked `cbox` organization's when there is one ({@see OrganizationLinks}).
 *
 * Read from the tables with the platform root named in the query, so the answer is the
 * same on whichever host asks.
 */
final readonly class OrganizationDirectory
{
    public function __construct(private OrganizationLinks $links) {}

    /**
     * Every organization this person is an active member of, by name.
     *
     * @return list<OrganizationEntry>
     */
    public function forSubject(string $subjectId): array
    {
        $root = $this->links->rootEnvironmentId();

        if ($root === null || $subjectId === '') {
            return [];
        }

        $rows = DB::table('memberships')
            ->where('environment_id', $root)
            ->where('user_id', $subjectId)
            ->where('status', MembershipStatus::Active->value)
            ->get(['organization_id', 'role']);

        $addresses = $this->links->addressesOf(
            array_values($rows->map(static fn (object $row): string => self::str($row->organization_id))->all()),
        );

        $entries = [];

        foreach ($rows as $row) {
            $address = $addresses[self::str($row->organization_id)] ?? null;

            // Only organizations that are still active: archiving an organization keeps its
            // memberships, and an archived one is nowhere anybody can work.
            if ($address !== null && $address['active']) {
                $entries[] = new OrganizationEntry(
                    id: self::str($row->organization_id),
                    slug: $address['slug'],
                    name: $address['name'],
                    role: self::str($row->role),
                );
            }
        }

        usort($entries, static fn (OrganizationEntry $a, OrganizationEntry $b): int => strnatcasecmp($a->name, $b->name) ?: strcmp($a->slug, $b->slug));

        return $entries;
    }

    /**
     * The organization `/orgs/{slug}` names FOR THIS PERSON, or null: a slug that names
     * nothing and a slug that names somebody else's organization are the same answer.
     */
    public function find(string $subjectId, string $slug): ?OrganizationEntry
    {
        $candidates = $this->links->candidatesFor($slug);

        if ($candidates === []) {
            return null;
        }

        foreach ($this->forSubject($subjectId) as $entry) {
            if ($entry->slug === $slug && in_array($entry->id, $candidates, true)) {
                return $entry;
            }
        }

        return null;
    }

    /** The slug a platform-root organization is addressed by, or null for an unknown id. */
    public function slugOf(string $organizationId): ?string
    {
        return $this->links->addressOf($organizationId)['slug'] ?? null;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
