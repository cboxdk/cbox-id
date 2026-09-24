<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Environment;

use App\Http\Controllers\Controller;
use App\Http\Resources\Environment\RoleAssignmentResource;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Environment plane › STAFF: roles a person holds everywhere in the environment rather than
 * inside one organization — the vendor's support agent who acts across every customer.
 *
 * {@see Roles::assignEverywhere()} decides what may be granted this way: a role that
 * belongs to no organization, app-agnostic or declared by one app (which then reaches that
 * app's tokens only). An organization's own role, or an orphaned one, is refused; so is a
 * grant that would form a segregation-of-duties conflict in any organization the person is
 * in. A role is named by id, or by manifest `key` with `?client_id=`.
 */
final class EnvironmentRoleController extends Controller
{
    use PaginatesEnvironmentResources;
    use ResolvesRoleReferences;

    public function index(string $userId): JsonResponse
    {
        if (User::query()->whereKey($userId)->doesntExist()) {
            return $this->notFound('user');
        }

        $grants = EnvironmentRoleAssignment::query()->where('user_id', $userId)->orderBy('role_id')->get();
        $roles = Role::query()->whereKey($grants->pluck('role_id')->all())->get()->keyBy('id');

        $data = [];

        foreach ($grants as $grant) {
            $role = $roles->get($grant->role_id);

            if ($role instanceof Role) {
                $data[] = RoleAssignmentResource::from($role, $userId, null, $grant->source);
            }
        }

        return response()->json(['data' => $data]);
    }

    /** 200 with the grant when the person holds the role everywhere, 404 when they do not. */
    public function show(Request $request, string $userId, string $roleId): JsonResponse
    {
        if (User::query()->whereKey($userId)->doesntExist()) {
            return $this->notFound('user');
        }

        $role = $this->role($roleId, $this->clientId($request));
        $grant = $role === null ? null : EnvironmentRoleAssignment::query()
            ->where('user_id', $userId)
            ->where('role_id', $role->id)
            ->first();

        if ($role === null || $grant === null) {
            return $this->refuse('not_found', 'That user does not hold that role everywhere.', 404);
        }

        return $this->item(RoleAssignmentResource::from($role, $userId, null, $grant->source));
    }

    /** Grant everywhere — idempotent. */
    public function update(Request $request, string $userId, string $roleId, Roles $roles): JsonResponse
    {
        if (User::query()->whereKey($userId)->doesntExist()) {
            return $this->notFound('user');
        }

        $role = $this->role($roleId, $this->clientId($request));

        if ($role === null) {
            return $this->notFound('role');
        }

        try {
            $grant = $roles->assignEverywhere($userId, $role->id, GrantSource::Manual);
        } catch (UnknownRole) {
            return $this->refuse('role_not_assignable', "The role [{$role->name}] belongs to one organization and cannot be granted everywhere.", 422);
        } catch (GrantRefused $refused) {
            return $this->refuse('role_conflict', $refused->getMessage(), 409);
        }

        return $this->item(RoleAssignmentResource::from($role, $userId, null, $grant->source));
    }

    /** Take an environment-wide grant back — idempotent. */
    public function destroy(Request $request, string $userId, string $roleId, Roles $roles): JsonResponse|Response
    {
        if (User::query()->whereKey($userId)->doesntExist()) {
            return $this->notFound('user');
        }

        $role = $this->role($roleId, $this->clientId($request));

        if ($role === null) {
            return $this->notFound('role');
        }

        $roles->unassignEverywhere($userId, $role->id);

        return response()->noContent();
    }

    private function clientId(Request $request): ?string
    {
        return $request->filled('client_id') ? $request->string('client_id')->toString() : null;
    }
}
