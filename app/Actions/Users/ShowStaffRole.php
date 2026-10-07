<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Actions\Roles\RoleFields;
use App\Http\Resources\Environment\RoleAssignmentResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\AccessControl\Models\EnvironmentRoleAssignment;

/**
 * Whether one person holds one role everywhere: the grant when they do, a 404 when they do
 * not. The role is named by id, or by manifest `key` with `client_id`.
 */
#[AsAction(
    name: 'users.environment_roles.get',
    summary: 'Whether a user holds a role everywhere in this environment: the grant, or 404. Name the role by id, or by manifest `key` with `client_id`.',
    scope: 'roles:read',
    danger: Danger::Read,
    schema: 'RoleAssignment',
    tag: 'Roles',
    rest: ['GET', '/users/{id}/environment-roles/{role_id}'],
)]
final readonly class ShowStaffRole implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The user id.'),
            Field::string('role_id')->inPath()->max(190)->describe('The role id, or its manifest `key` with `client_id`.'),
            Field::string('client_id')->max(255)->describe('The app whose manifest `key` `role_id` is.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $user = UserFields::find($context->string('id'));
        $role = RoleFields::reference($context->string('role_id'), $context->nullableString('client_id'));

        $grant = $role === null ? null : EnvironmentRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->first();

        if ($role === null || $grant === null) {
            throw new ActionRefused('not_found', 'That user does not hold that role everywhere.', 404);
        }

        return ActionResult::item($grant, RoleAssignmentResource::from($role, $user->id, null, $grant->source));
    }
}
