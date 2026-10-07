<?php

declare(strict_types=1);

namespace App\Actions\Permissions;

use App\Actions\Keys\KeyFields;
use App\Actions\Roles\RoleAuthority;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use Cbox\Id\AccessControl\Models\Permission;

/**
 * Shared lookups and the wire shape for the permission actions. A helper, not an action.
 *
 * A permission is a `feature:action` key, and it arrives one of two ways: APP-DECLARED
 * (`client_id` set), synced from an app's manifest — the app is its source of truth, so it
 * is read-only here — or MANUAL (`client_id` null), authored here for an organization that
 * runs no SDK integration and still needs its own vocabulary to build roles from.
 */
final class PermissionFields
{
    /**
     * A MANUAL permission in this environment that the caller may write: with the
     * environment's authority any of this environment's manual permissions, and for one
     * organization's administrator exactly that organization's own — never the shared tier
     * their roles are composed from, which every peer's roles use too. App-declared keys,
     * and the platform-global rows an operator seeded, never resolve here.
     *
     * @throws ActionRefused
     */
    public static function writable(ActionContext $context, string $id): Permission
    {
        $authority = RoleAuthority::of($context->principal);

        return Permission::query()
            ->whereKey($id)
            ->whereNull('client_id')
            ->where('environment_id', KeyFields::environmentId($context->principal))
            ->when($authority->tenant !== null, fn ($query) => $query->ownedByOrganization($authority->tenant))
            ->first() ?? throw ActionRefused::notFound('permission');
    }

    /**
     * `Permission` in the spec.
     *
     * @return array<string, mixed>
     */
    public static function present(Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'description' => $permission->description,
            'client_id' => $permission->client_id,
            'organization_id' => $permission->organization_id,
            'tenant_assignable' => $permission->tenant_assignable,
            'manual' => $permission->client_id === null,
            'orphaned' => $permission->orphaned_at !== null,
        ];
    }
}
