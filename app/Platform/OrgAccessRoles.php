<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Exceptions\RoleNotTenantAssignable;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The RBAC access-roles assignable to a subject within one organization, plus the
 * read models the console needs to render and explain them.
 *
 * An org's assignable set is the UNION of: environment-wide and org-scoped MANUAL
 * roles (admin-authored in the Roles console), and the APP-DECLARED roles of the apps
 * that org can use. Never another org's role, never an orphaned one. These are the
 * real "what a person can do inside the apps" roles — distinct from the coarse org
 * membership tier (owner/admin/member), which only governs who administers the org.
 *
 * Shared by the environment-admin organization and user consoles so both surface the
 * exact same catalog and permission explanations.
 *
 * TWO PLANES, TWO SETS. {@see assignable()} is what an ENVIRONMENT administrator may grant
 * inside an organization — staff-only roles included, because giving the vendor's support
 * lead "Support" inside one customer is exactly their call. {@see tenantAssignable()} is
 * what the organization's OWN administrators may offer: the framework's
 * {@see Roles::tenantAssignableRoles()}, which leaves staff roles out. A customer handing
 * the vendor's cross-customer role to one of their own people would be a privilege
 * escalation out of their tenancy.
 */
final class OrgAccessRoles
{
    /**
     * What a tenant surface says when a posted role id is not one it offers.
     *
     * ONE sentence for a staff-only role, another organization's role and an id that
     * matches nothing. The framework keeps those apart only in its exception class
     * ({@see RoleNotTenantAssignable}), for the logs: told "that one is staff-only", a
     * tenant administrator could probe the vendor's role catalog one id at a time. What
     * they must be told is that the write did not happen — a redirect that says nothing
     * reads as success.
     */
    public const NOT_OFFERED = 'That access role is not offered in this organization. Choose one from the list.';

    public function __construct(private readonly Roles $roles) {}

    /**
     * The roles an ENVIRONMENT administrator may grant to people in this organization,
     * ordered by name. Staff-only roles included; a tenant-facing surface uses
     * {@see tenantAssignable()}.
     *
     * @return Collection<int, Role>
     */
    public function assignable(string $organizationId): Collection
    {
        return Role::query()
            ->whereNull('orphaned_at')
            ->where(function ($q) use ($organizationId): void {
                // Manual roles: environment-wide (no org) or scoped to THIS org.
                $q->where(fn ($x) => $x->whereNull('client_id')
                    ->where(fn ($y) => $y->whereNull('organization_id')->orWhere('organization_id', $organizationId)))
                    // App-declared roles for apps this org can use.
                    ->orWhere(fn ($x) => $x->whereIn('client_id', $this->orgClientIds($organizationId)));
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * The roles this organization's own administrators may offer, ordered by name — the
     * tenant plane's picker, and the set an invitation may carry.
     *
     * The framework's list (this organization's roles and the environment's shared ones,
     * never a staff-only or an orphaned one) narrowed by the one rule it does not state:
     * an app-declared role only for an app this organization can use. The write path
     * asks the same framework predicate through {@see GrantAccessRole::grantAsTenant()},
     * so a role this list hides is a role that grant refuses.
     *
     * @return Collection<int, Role>
     */
    public function tenantAssignable(string $organizationId): Collection
    {
        $usable = array_flip($this->orgClientIds($organizationId));

        return collect($this->roles->tenantAssignableRoles($organizationId))
            ->filter(static fn (Role $role): bool => $role->client_id === null || isset($usable[$role->client_id]))
            ->values();
    }

    /** {@see tenantAssignable()} for one role: whether an organization's own admin may grant it. */
    public function isTenantAssignable(string $organizationId, string $roleId): bool
    {
        return $this->tenantAssignable($organizationId)->contains(fn (Role $r): bool => $r->id === $roleId);
    }

    /**
     * clientId => app name, for the app-declared roles among $roles (so the picker can
     * group "Org roles" vs each app's own roles).
     *
     * @param  Collection<int, Role>  $roles
     * @return array<string, string>
     */
    public function appNames(Collection $roles): array
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

        $out = [];
        foreach (Client::query()->whereIn('client_id', array_keys($clientIds))->get(['client_id', 'name']) as $client) {
            $out[self::str($client->client_id)] = self::str($client->name);
        }

        return $out;
    }

    /**
     * roleId => the permission names it grants — so the console can show what each role
     * actually lets a member do ("effective access across apps").
     *
     * @param  Collection<int, Role>  $roles
     * @return array<string, list<string>>
     */
    public function permissions(Collection $roles): array
    {
        $ids = [];
        foreach ($roles as $role) {
            $ids[] = $role->id;
        }

        if ($ids === []) {
            return [];
        }

        $rows = DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->whereIn('role_permission.role_id', $ids)
            ->orderBy('permissions.name')
            ->get(['role_permission.role_id', 'permissions.name']);

        $out = [];
        foreach ($rows as $row) {
            $out[self::str($row->role_id)][] = self::str($row->name);
        }

        return $out;
    }

    /**
     * userId => the role ids that user holds in this org.
     *
     * @param  list<string>|null  $userIds  narrow to these people; null reads the whole
     *                                      organization, which grows with its end-user count
     * @return array<string, list<string>>
     */
    public function assignmentsByUser(string $organizationId, ?array $userIds = null): array
    {
        $query = RoleAssignment::query()->where('organization_id', $organizationId);

        // NARROWED TO THE PAGE, when the caller can say which people it is rendering.
        // Unbounded, this is one row per member per role held — a set that grows with the
        // organization's END-USER count on a page that only ever draws twenty-five of
        // them, and `with()` re-runs it on every interaction.
        if ($userIds !== null) {
            if ($userIds === []) {
                return [];
            }

            $query->whereIn('user_id', $userIds);
        }

        $out = [];

        foreach ($query->get(['user_id', 'role_id']) as $assignment) {
            $out[self::str($assignment->user_id)][] = self::str($assignment->role_id);
        }

        return $out;
    }

    /**
     * The role ids one subject holds in this org.
     *
     * @return list<string>
     */
    public function assignedTo(string $organizationId, string $userId): array
    {
        $out = [];
        foreach (RoleAssignment::query()->where('organization_id', $organizationId)->where('user_id', $userId)->get(['role_id']) as $assignment) {
            $out[] = self::str($assignment->role_id);
        }

        return $out;
    }

    /**
     * Whether a role id is genuinely assignable in this org — the allow-list the
     * console validates every grant against, so a posted id that matches nothing (or
     * another org's role) is never trusted.
     */
    public function isAssignable(string $organizationId, string $roleId): bool
    {
        return $this->assignable($organizationId)->contains(fn (Role $r): bool => $r->id === $roleId);
    }

    /**
     * The OAuth client ids whose app-declared roles this org may use: apps scoped to
     * this org, plus environment-global apps (no org).
     *
     * @return list<string>
     */
    private function orgClientIds(string $organizationId): array
    {
        $out = [];
        foreach (Client::query()->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $organizationId))->get(['client_id']) as $client) {
            $out[] = self::str($client->client_id);
        }

        return $out;
    }

    /** Safely render a scalar DB/model value as a string (never casts mixed blindly). */
    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
