<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Resources\Environment\RoleAssignmentResource;
use App\Platform\GrantAccessRole;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Organization\Contracts\Memberships;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Environment plane › the access roles one member holds inside one organization.
 *
 * THE ENVIRONMENT'S AUTHORITY, NOT THE TENANT'S. This API is the app vendor's own backend,
 * so it grants from the environment plane — {@see GrantAccessRole::grant()}, the call the
 * environment console makes — and may therefore give a member a STAFF-ONLY role
 * (`tenant_assignable: false`) inside this one organization: the vendor's support lead
 * getting "Support" at one customer. What an organization's own administrators may grant is
 * untouched by this: their consoles, their invitations and their directory mappings still
 * go through the tenant plane's guard, which refuses a staff role outright.
 *
 * Segregation of duties is checked on every grant, exactly as in the consoles. A role is
 * named by id, or by manifest `key` with `?client_id=` naming the app that declared it.
 */
final class MemberRoleController extends Controller
{
    use PaginatesEnvironmentResources;
    use ResolvesRoleReferences;

    public function index(string $id, string $userId, Memberships $memberships): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null || $memberships->of($organization->id, $userId) === null) {
            return $this->notFound('member');
        }

        // Direct assignments made AT this organization. Environment-wide (staff) grants are
        // their own list, under /users/{id}/environment-roles.
        $assignments = RoleAssignment::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $userId)
            ->orderBy('role_id')
            ->get();

        $roles = Role::query()->whereKey($assignments->pluck('role_id')->all())->get()->keyBy('id');

        $data = [];

        foreach ($assignments as $assignment) {
            $role = $roles->get($assignment->role_id);

            if ($role instanceof Role) {
                $data[] = RoleAssignmentResource::from($role, $userId, $organization->id, $assignment->source);
            }
        }

        return response()->json(['data' => $data]);
    }

    /** Grant — idempotent: granting a role the member already holds changes nothing. */
    public function update(Request $request, string $id, string $userId, string $roleId, Memberships $memberships, OrgAccessRoles $catalog, GrantAccessRole $grants): JsonResponse
    {
        $organization = $this->organization($id);

        if ($organization === null || $memberships->of($organization->id, $userId) === null) {
            return $this->notFound('member');
        }

        $role = $this->role($roleId, $this->clientId($request));

        if ($role === null) {
            return $this->notFound('role');
        }

        // The ENVIRONMENT plane's set: this organization's own roles, the environment's
        // shared ones and the roles of the apps it can use — staff-only ones included.
        if (! $catalog->isAssignable($organization->id, $role->id)) {
            return $this->refuse('role_not_assignable', "The role [{$role->name}] cannot be held in this organization — it belongs to another organization, or to an app this organization cannot use.", 422);
        }

        try {
            $refusal = $grants->grant($organization->id, $userId, $role->id, GrantSource::Manual);
        } catch (GrantRefused $refused) {
            return $this->refuse('role_conflict', $refused->getMessage(), 409);
        } catch (UnknownRole) {
            return $this->notFound('role');
        }

        if ($refusal !== null) {
            return $this->refuse('role_conflict', $refusal->message(), 409);
        }

        return $this->item(RoleAssignmentResource::from($role, $userId, $organization->id, GrantSource::Manual));
    }

    /** Take a role back — idempotent: removing one the member does not hold is a 204 too. */
    public function destroy(Request $request, string $id, string $userId, string $roleId, Memberships $memberships, GrantAccessRole $grants): JsonResponse|Response
    {
        $organization = $this->organization($id);

        if ($organization === null || $memberships->of($organization->id, $userId) === null) {
            return $this->notFound('member');
        }

        $role = $this->role($roleId, $this->clientId($request));

        if ($role === null) {
            return $this->notFound('role');
        }

        $grants->revoke($organization->id, $userId, $role->id);

        return response()->noContent();
    }

    private function clientId(Request $request): ?string
    {
        return $request->filled('client_id') ? $request->string('client_id')->toString() : null;
    }
}
