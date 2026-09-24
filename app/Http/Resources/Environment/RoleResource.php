<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\AccessControl\Models\Role;

/**
 * A role — `Role` in the spec. `key` is the app's manifest key for an app-declared role
 * (null for one an administrator made in the console); `client_id` is the app that
 * declared it; `organization_id` is set only for a role one organization made for itself.
 * `tenant_assignable: false` is a STAFF role: an organization's own administrators can never
 * grant it, and neither can an invitation.
 */
final class RoleResource
{
    /**
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    public static function from(Role $role, array $permissions): array
    {
        return [
            'id' => $role->id,
            'key' => $role->key,
            'name' => $role->name,
            'description' => $role->description,
            'client_id' => $role->client_id,
            'organization_id' => $role->organization_id,
            'tenant_assignable' => $role->tenant_assignable,
            'permissions' => $permissions,
        ];
    }
}
