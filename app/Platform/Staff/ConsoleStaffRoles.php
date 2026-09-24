<?php

declare(strict_types=1);

namespace App\Platform\Staff;

use App\Platform\SodGuard;
use App\Platform\SodRefusal;
use App\Platform\Staff\Contracts\StaffRoles;
use App\Platform\Staff\ValueObjects\StaffGrant;
use App\Platform\Staff\ValueObjects\StaffGrantRefusal;
use App\Platform\Staff\ValueObjects\StaffRole;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * {@see StaffRoles} over the framework's {@see Roles} contract and the console's own
 * segregation-of-duties guard.
 */
class ConsoleStaffRoles implements StaffRoles
{
    public function __construct(
        private readonly Roles $roles,
        private readonly SodGuard $sod,
        private readonly Memberships $memberships,
        private readonly Subjects $subjects,
    ) {}

    public function grantable(): array
    {
        $roles = array_values($this->grantableQuery()->orderBy('name')->get()->all());
        $apps = $this->appNames($roles);

        $out = array_map(fn (Role $role): StaffRole => $this->toStaffRole($role, $apps), $roles);

        // All-apps roles first, then each app's in the app's name order — the order the
        // Staff page groups them in, so the picker and the list read the same way down.
        usort($out, static fn (StaffRole $a, StaffRole $b): int => [$a->appName !== null, $a->appName ?? '', $a->name]
            <=> [$b->appName !== null, $b->appName ?? '', $b->name]);

        return $out;
    }

    public function isGrantable(string $roleId): bool
    {
        return $this->grantableQuery()->whereKey($roleId)->exists();
    }

    public function grants(): array
    {
        $assignments = $this->roles->assignmentsEverywhere();

        if ($assignments === []) {
            return [];
        }

        $roleIds = array_values(array_unique(array_map(
            static fn (EnvironmentRoleAssignment $assignment): string => $assignment->role_id,
            $assignments,
        )));

        $roleRows = array_values(Role::query()->whereIn('id', $roleIds)->get()->all());
        $apps = $this->appNames($roleRows);

        $roles = [];
        foreach ($roleRows as $role) {
            $roles[$role->id] = $this->toStaffRole($role, $apps);
        }

        $people = [];
        $userIds = array_values(array_unique(array_map(
            static fn (EnvironmentRoleAssignment $assignment): string => $assignment->user_id,
            $assignments,
        )));

        foreach ($this->subjects->findMany($userIds) as $subject) {
            $people[$subject->id] = $subject;
        }

        $grants = [];

        foreach ($assignments as $assignment) {
            // A grant whose role row is gone names nothing anybody could act on. The
            // framework deletes these with the role; one that survives is not shown as a
            // bare id pretending to be a role.
            $role = $roles[$assignment->role_id] ?? null;

            if ($role === null) {
                continue;
            }

            $person = $people[$assignment->user_id] ?? null;

            $grants[] = new StaffGrant(
                userId: $assignment->user_id,
                userName: $person?->name,
                userEmail: $person?->email,
                role: $role,
                source: $assignment->source,
            );
        }

        return $grants;
    }

    public function heldBy(string $userId): array
    {
        return $this->roles->everywhereFor($userId);
    }

    public function grant(string $userId, string $roleId): ?StaffGrantRefusal
    {
        // Between staff roles first: that pair forms whether or not the person belongs to
        // any organization, and it is the one no organization-scoped check can see.
        $staffConflict = $this->sod->refuseEverywhere($roleId, $this->heldBy($userId));

        if ($staffConflict !== null) {
            return new StaffGrantRefusal($staffConflict);
        }

        // Then in every organization the grant is about to land in, through the same guard
        // every organization-scoped grant uses — so the refusal names the policy and the
        // role it collides with, and the organization where the person already holds it.
        foreach ($this->memberships->forUser($userId) as $membership) {
            $conflict = $this->sod->refuse($membership->organization_id, $userId, $roleId);

            if ($conflict !== null) {
                return new StaffGrantRefusal($conflict, $this->organizationName($membership->organization_id));
            }
        }

        try {
            $this->roles->assignEverywhere($userId, $roleId, GrantSource::Manual);
        } catch (GrantRefused $refused) {
            // The framework asks its own grant guard in every organization too. Anything
            // it refuses that the console's guard did not describe is still a refusal, and
            // is reported rather than swallowed.
            return new StaffGrantRefusal(
                new SodRefusal('a segregation-of-duties policy', $this->roleName($roleId), []),
                $this->organizationName($refused->organizationId),
            );
        }

        return null;
    }

    public function revoke(string $userId, string $roleId): void
    {
        $this->roles->unassignEverywhere($userId, $roleId);
    }

    /**
     * The roles that may be granted everywhere: no organization owns them, and they are not
     * orphaned — the framework's own acceptance rule for assignEverywhere(), stated here so
     * the picker and the write agree.
     *
     * @return Builder<Role>
     */
    private function grantableQuery(): Builder
    {
        return Role::query()
            ->whereNull('organization_id')
            ->whereNull('orphaned_at');
    }

    /**
     * @param  array<string, string>  $apps
     */
    private function toStaffRole(Role $role, array $apps): StaffRole
    {
        return new StaffRole(
            id: $role->id,
            name: $role->name,
            clientId: $role->client_id,
            appName: $role->client_id === null ? null : ($apps[$role->client_id] ?? $role->client_id),
            tenantAssignable: $role->tenant_assignable,
        );
    }

    /**
     * client id => app name for the app-declared roles among these.
     *
     * @param  list<Role>  $roles
     * @return array<string, string>
     */
    private function appNames(array $roles): array
    {
        $clientIds = [];

        foreach ($roles as $role) {
            if (is_string($role->client_id) && $role->client_id !== '') {
                $clientIds[$role->client_id] = true;
            }
        }

        if ($clientIds === []) {
            return [];
        }

        $names = [];

        foreach (Client::query()->whereIn('client_id', array_keys($clientIds))->get(['client_id', 'name']) as $client) {
            $names[$client->client_id] = $client->name;
        }

        return $names;
    }

    private function organizationName(string $organizationId): string
    {
        $name = Organization::query()->whereKey($organizationId)->value('name');

        return is_string($name) && $name !== '' ? $name : $organizationId;
    }

    private function roleName(string $roleId): string
    {
        $name = Role::query()->whereKey($roleId)->value('name');

        return is_string($name) && $name !== '' ? $name : $roleId;
    }
}
