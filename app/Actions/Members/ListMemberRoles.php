<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Actions\Organizations\OrganizationFields;
use App\Http\Resources\Environment\RoleAssignmentResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;

/**
 * The access roles one member holds directly inside one organization. Environment-wide
 * (staff) grants are their own list, under `/users/{id}/environment-roles`.
 */
#[AsAction(
    name: 'members.roles.list',
    summary: 'List the access roles a member holds inside one organization (staff roles held everywhere are listed under the user).',
    scope: 'roles:read',
    danger: Danger::Read,
    schema: 'RoleAssignment',
    tag: 'Roles',
    rest: ['GET', '/organizations/{organization_id}/members/{user_id}/roles'],
)]
final readonly class ListMemberRoles implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('user_id')->inPath()->max(64)->describe('The member\'s user id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $member = MemberFields::find($organization->id, $context->string('user_id'));

        $assignments = RoleAssignment::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $member->user_id)
            ->orderBy('role_id')
            ->get();

        $roles = Role::query()->whereKey($assignments->pluck('role_id')->all())->get()->keyBy('id');

        $rows = [];

        foreach ($assignments as $assignment) {
            $role = $roles->get($assignment->role_id);

            if ($role instanceof Role) {
                $rows[] = RoleAssignmentResource::from($role, $member->user_id, $organization->id, $assignment->source);
            }
        }

        return ActionResult::items($assignments, $rows);
    }
}
