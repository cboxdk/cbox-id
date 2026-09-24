<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Resources\Environment\RoleResource;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Environment plane › the role catalogue: every role in the environment that can still be
 * granted (orphaned roles — dropped from their app's manifest — are left out), with the
 * permissions each carries.
 *
 * Not paginated: it is a catalogue, bounded by what apps declare and administrators define,
 * and a caller mapping its manifest keys to ids wants all of it at once. Narrow it with
 * `?client_id=` (one app's roles) or `?organization_id=` (what can be granted there).
 */
final class RoleController extends Controller
{
    use PaginatesEnvironmentResources;

    public function index(Request $request, OrgAccessRoles $catalog): JsonResponse
    {
        $clientId = $request->filled('client_id') ? $request->string('client_id')->toString() : null;
        $organizationId = $request->filled('organization_id') ? $request->string('organization_id')->toString() : null;

        if ($organizationId !== null) {
            if ($this->organization($organizationId) === null) {
                return $this->notFound('organization');
            }

            $roles = $catalog->assignable($organizationId);
        } else {
            $roles = Role::query()->whereNull('orphaned_at')->orderBy('name')->get();
        }

        if ($clientId !== null) {
            $roles = $roles->filter(static fn (Role $role): bool => $role->client_id === $clientId)->values();
        }

        $permissions = $catalog->permissions($roles);

        return response()->json([
            'data' => $roles->map(static fn (Role $role): array => RoleResource::from($role, $permissions[$role->id] ?? []))->values()->all(),
        ]);
    }
}
