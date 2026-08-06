<?php

declare(strict_types=1);

namespace App\Platform;

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
 */
final class OrgAccessRoles
{
    /**
     * The roles assignable to people in this organization, ordered by name.
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
     * userId => the role ids that user holds in this org (all members at once).
     *
     * @return array<string, list<string>>
     */
    public function assignmentsByUser(string $organizationId): array
    {
        $out = [];
        foreach (RoleAssignment::query()->where('organization_id', $organizationId)->get(['user_id', 'role_id']) as $assignment) {
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
