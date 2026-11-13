<?php

declare(strict_types=1);

namespace App\Platform;

use App\Actions\Permissions\CreatePermission;
use Cbox\Id\AccessControl\Contracts\AccessChecker;
use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\AccessControl\ValueObjects\AppAccessClaims;
use Cbox\Id\Organization\Contracts\OrganizationHierarchy;
use Illuminate\Support\Facades\DB;

/**
 * A token for an app carries a key THAT APP declares only when the user holds it through
 * the app's own declared row.
 *
 * The framework's forToken() collects permission NAMES from every role relevant to the app,
 * and a manual permission shares the unqualified `feature:action` namespace with declared
 * ones. So a manual row spelled like an app's key — written before the app declared it, or
 * in data that predates the console's refusal ({@see CreatePermission})
 * — would read to the app as its own key, and walk around `tenant_assignable`, which is how
 * an app keeps a privileged key off the tenant plane.
 *
 * Strictly subtractive: it only ever removes a name the inner answer carried, and only a
 * name this app declares, so every other claim is the framework's answer unchanged. `can()`
 * and `permissionsFor()` are cross-app by contract and pass through.
 */
final readonly class OwnDeclaredPermissionClaims implements AccessChecker
{
    public function __construct(
        private AccessChecker $inner,
        private OrganizationHierarchy $hierarchy,
    ) {}

    public function can(string $userId, string $permission, string $organizationId): bool
    {
        return $this->inner->can($userId, $permission, $organizationId);
    }

    public function permissionsFor(string $userId, string $organizationId): array
    {
        return $this->inner->permissionsFor($userId, $organizationId);
    }

    public function forToken(string $userId, ?string $organizationId, string $clientId): AppAccessClaims
    {
        $claims = $this->inner->forToken($userId, $organizationId, $clientId);

        if ($claims->permissions === []) {
            return $claims;
        }

        // permission id => name: this app's own declared rows among what the token carries
        $declared = [];
        foreach (DB::table('permissions')->where('client_id', $clientId)->whereIn('name', $claims->permissions)->get(['id', 'name']) as $row) {
            if (is_string($row->id) && is_string($row->name)) {
                $declared[$row->id] = $row->name;
            }
        }

        if ($declared === []) {
            return $claims;
        }

        $unheld = array_diff(array_unique(array_values($declared)), $this->heldThroughDeclaredRows($userId, $organizationId, $clientId, $declared));

        if ($unheld === []) {
            return $claims;
        }

        return new AppAccessClaims($claims->roles, array_values(array_diff($claims->permissions, $unheld)));
    }

    /**
     * Which of the app's declared rows the user holds, through a role that may reach this
     * app's token — the same three fences the framework's resolution applies: the role is
     * org-wide or the app's own, an organization grant names a role of that organization
     * or its ancestors (or none), an environment-wide grant names a role no organization owns.
     *
     * @param  array<string, string>  $declared  permission id => name
     * @return list<string> names
     */
    private function heldThroughDeclaredRows(string $userId, ?string $organizationId, string $clientId, array $declared): array
    {
        // role id => the declared names it grants
        $grants = [];
        foreach (DB::table('role_permission')->whereIn('permission_id', array_keys($declared))->get(['role_id', 'permission_id']) as $row) {
            if (is_string($row->role_id) && is_string($row->permission_id) && isset($declared[$row->permission_id])) {
                $grants[$row->role_id][] = $declared[$row->permission_id];
            }
        }

        if ($grants === []) {
            return [];
        }

        $scopes = $organizationId === null
            ? []
            : array_merge([$organizationId], $this->hierarchy->ancestors($organizationId));

        $reachable = Role::query()
            ->whereIn('id', array_keys($grants))
            ->where(fn ($query) => $query->whereNull('client_id')->orWhere('client_id', $clientId));

        $everywhere = EnvironmentRoleAssignment::query()
            ->where('user_id', $userId)
            ->whereIn('role_id', (clone $reachable)->select('id')->whereNull('organization_id'))
            ->pluck('role_id')
            ->all();

        $inOrg = $scopes === [] ? [] : RoleAssignment::query()
            ->where('user_id', $userId)
            ->whereIn('organization_id', $scopes)
            ->whereIn('role_id', (clone $reachable)->select('id')->where(fn ($query) => $query
                ->whereNull('organization_id')
                ->orWhereIn('organization_id', $scopes)))
            ->pluck('role_id')
            ->all();

        $names = [];
        foreach ([...$everywhere, ...$inOrg] as $roleId) {
            if (is_string($roleId) && isset($grants[$roleId])) {
                array_push($names, ...$grants[$roleId]);
            }
        }

        return array_values(array_unique($names));
    }
}
