<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Http\Resources\Environment\RoleResource;
use App\Platform\Actions\ActionRefused;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Shared lookups for the role actions. A helper, not an action.
 *
 * A role is named by its id, or — because an app knows its own manifest by KEY, not by the
 * ids this server minted for it — by `key` together with the `client_id` of the app that
 * declared it. A key is only unique within one app, so without a client id only an id
 * resolves. Orphaned roles (dropped from their app's latest manifest) never resolve: a role
 * nobody can see in a console is exactly the one that must not be granted by name.
 */
final class RoleFields
{
    /**
     * The role a reference names in this environment, or null.
     *
     * `$orphaned` widens it to roles their app stopped declaring — only ever to take one
     * BACK: a grant of an orphaned role is still a grant, and must stay revocable.
     */
    public static function reference(string $reference, ?string $clientId = null, bool $orphaned = false): ?Role
    {
        return Role::query()
            ->when(! $orphaned, fn (Builder $query): Builder => $query->whereNull('orphaned_at'))
            ->where(function (Builder $query) use ($reference, $clientId): void {
                $query->whereKey($reference);

                if ($clientId !== null && $clientId !== '') {
                    $query->orWhere(fn (Builder $byKey): Builder => $byKey->where('client_id', $clientId)->where('key', $reference));
                }
            })
            ->first();
    }

    /**
     * {@see self::reference()}, or the 404 every door answers for a role that is not here.
     *
     * @throws ActionRefused
     */
    public static function find(string $reference, ?string $clientId = null, bool $orphaned = false): Role
    {
        return self::reference($reference, $clientId, $orphaned) ?? throw ActionRefused::notFound('role');
    }

    /**
     * `Role` in the spec: the role and the permission keys it carries.
     *
     * @return array<string, mixed>
     */
    public static function present(Role $role): array
    {
        $permissions = app(OrgAccessRoles::class)->permissions(new Collection([$role]));

        return RoleResource::from($role, $permissions[$role->id] ?? []);
    }
}
