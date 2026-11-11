<?php

declare(strict_types=1);

namespace App\Platform\Organizations;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Illuminate\Support\Facades\DB;

/**
 * The organizations a person belongs to, and the slug `/orgs/{slug}` names each one by.
 *
 * ONE REALM, ONE ROW. An organization exists once, in the platform's own environment, and
 * every Cbox app addresses it by that row's slug. There is no second copy to pair it with
 * and nothing to translate: the address is the organization's own slug and the name its
 * own name.
 *
 * Read from the tables with the environment named in the query, never through the
 * environment-scoped models, so the answer is the same on whichever host asks.
 */
final class OrganizationDirectory
{
    /** @var array<string, array{slug: string, name: string, active: bool}> */
    private array $addresses = [];

    private mixed $root = null;

    /**
     * Every ACTIVE organization this person is an active member of, by name, each once.
     *
     * Closing or suspending an organization keeps its memberships, and one that is not
     * active is nowhere anybody can work: it is not a place to switch to. A second membership row in the same
     * organization (a re-invited member whose old row was never removed) is still one
     * organization.
     *
     * @return list<OrganizationEntry>
     */
    public function forSubject(string $subjectId): array
    {
        $root = $this->realmEnvironmentId();

        if ($root === null || $subjectId === '') {
            return [];
        }

        $rows = DB::table('memberships')
            ->where('environment_id', $root)
            ->where('user_id', $subjectId)
            ->where('status', MembershipStatus::Active->value)
            ->orderBy('created_at')
            ->get(['organization_id', 'role']);

        $addresses = $this->addressesOf(
            array_values($rows->map(static fn (object $row): string => self::str($row->organization_id))->all()),
        );

        $entries = [];

        foreach ($rows as $row) {
            $id = self::str($row->organization_id);
            $address = $addresses[$id] ?? null;

            if ($address !== null && $address['active'] && ! isset($entries[$id])) {
                $entries[$id] = new OrganizationEntry(
                    id: $id,
                    slug: $address['slug'],
                    name: $address['name'],
                    role: self::str($row->role),
                );
            }
        }

        $entries = array_values($entries);

        usort($entries, static fn (OrganizationEntry $a, OrganizationEntry $b): int => strnatcasecmp($a->name, $b->name) ?: strcmp($a->slug, $b->slug));

        return $entries;
    }

    /**
     * The organization `/orgs/{slug}` names FOR THIS PERSON, or null: a slug that names
     * nothing and a slug that names somebody else's organization are the same answer.
     */
    public function find(string $subjectId, string $slug): ?OrganizationEntry
    {
        if ($slug === '') {
            return null;
        }

        foreach ($this->forSubject($subjectId) as $entry) {
            if ($entry->slug === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The organization a slug names, whoever asks: for resolving an address that is then
     * authorized by something else (an environment console path, by the environment's own
     * access check). Null for a slug that names nothing.
     */
    public function idOf(string $slug): ?string
    {
        $root = $this->realmEnvironmentId();

        if ($root === null || $slug === '') {
            return null;
        }

        $id = DB::table('organizations')->where('environment_id', $root)->where('slug', $slug)->value('id');

        return is_scalar($id) ? (string) $id : null;
    }

    /** The slug an organization is addressed by, or null for an unknown id. */
    public function slugOf(string $organizationId): ?string
    {
        return $this->addressOf($organizationId)['slug'] ?? null;
    }

    /**
     * The slug and the name of an organization, and whether it is active.
     *
     * @return array{slug: string, name: string, active: bool}|null
     */
    public function addressOf(string $organizationId): ?array
    {
        return $this->addressesOf([$organizationId])[$organizationId] ?? null;
    }

    /**
     * @param  list<string>  $organizationIds
     * @return array<string, array{slug: string, name: string, active: bool}>
     */
    public function addressesOf(array $organizationIds): array
    {
        $root = $this->realmEnvironmentId();
        $missing = array_values(array_diff(array_unique($organizationIds), array_keys($this->addresses)));

        if ($missing !== [] && $root !== null) {
            $rows = DB::table('organizations')
                ->where('environment_id', $root)
                ->whereIn('id', $missing)
                ->get(['id', 'slug', 'name', 'status']);

            foreach ($rows as $row) {
                $this->addresses[self::str($row->id)] = [
                    'slug' => self::str($row->slug),
                    'name' => self::str($row->name),
                    'active' => self::str($row->status) === OrganizationStatus::Active->value,
                ];
            }
        }

        return array_intersect_key($this->addresses, array_flip($organizationIds));
    }

    /** Forget what this instance read, after a write elsewhere changed it. */
    public function flush(): void
    {
        $this->addresses = [];
    }

    /**
     * The environment the organizations live in: the `is_default` one. A deployment
     * without one (a single-environment install, most of the test suite) runs its console
     * in the environment the request stands in, so that is the one whose memberships count.
     */
    private function realmEnvironmentId(): ?string
    {
        // Once per request: it does not move, and the shell and the page both ask.
        $id = $this->root ??= DB::table('environments')->where('is_default', true)->value('id');

        if (is_string($id)) {
            return $id;
        }

        $ambient = app(EnvironmentContext::class)->current()?->environmentKey();

        return is_string($ambient) && $ambient !== '' ? $ambient : null;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
