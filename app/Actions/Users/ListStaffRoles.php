<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Http\Resources\Environment\RoleAssignmentResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;
use Cbox\Id\AccessControl\Models\Role;

/**
 * STAFF ROLES — the roles one person holds everywhere in this environment rather than in
 * one organization: the vendor's support agent who acts across every customer. Grants made
 * inside an organization are that member's own list, under the organization.
 */
#[AsAction(
    name: 'users.environment_roles.list',
    summary: 'List the staff roles a user holds everywhere in this environment (not those held inside one organization).',
    scope: 'roles:read',
    danger: Danger::Read,
    schema: 'RoleAssignment',
    tag: 'Roles',
    rest: ['GET', '/users/{id}/environment-roles'],
)]
final readonly class ListStaffRoles implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));

        $grants = EnvironmentRoleAssignment::query()->where('user_id', $user->id)->orderBy('role_id')->get();
        $roles = Role::query()->whereKey($grants->pluck('role_id')->all())->get()->keyBy('id');

        $rows = [];

        foreach ($grants as $grant) {
            $role = $roles->get($grant->role_id);

            if ($role instanceof Role) {
                $rows[] = RoleAssignmentResource::from($role, $user->id, null, $grant->source);
            }
        }

        return ActionResult::items($grants, $rows);
    }
}
