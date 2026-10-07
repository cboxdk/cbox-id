<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Actions\Organizations\OrganizationFields;
use App\Actions\Roles\RoleFields;
use App\Http\Resources\Environment\RoleAssignmentResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Enums\GrantSource;

/**
 * Grant one access role to one member inside one organization.
 *
 * THE ENVIRONMENT'S AUTHORITY, NOT THE TENANT'S. This is the app vendor's backend (or the
 * environment's own administrators), so it grants from the environment plane and may give a
 * member a STAFF-ONLY role (`tenant_assignable: false`) inside this one organization: the
 * vendor's support lead getting "Support" at one customer. What an organization's own
 * administrators may grant is untouched — their consoles, invitations and directory mappings
 * still go through the tenant plane's guard, which refuses a staff role outright.
 *
 * Segregation of duties is asked on every grant, and a conflict is a 409 naming both roles.
 * Idempotent: granting a role the member already holds changes nothing.
 */
#[AsAction(
    name: 'members.roles.grant',
    summary: 'Grant a member an access role inside one organization, with the environment\'s authority (staff-only roles included). Name the role by id, or by manifest `key` with `client_id`.',
    scope: 'roles:write',
    danger: Danger::Write,
    schema: 'RoleAssignment',
    tag: 'Roles',
    rest: ['PUT', '/organizations/{organization_id}/members/{user_id}/roles/{role_id}'],
    consoleRoutes: ['environment.organizations.members.access', 'environment.users.organizations.access'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class GrantMemberRole implements Action
{
    public function __construct(private OrgAccessRoles $catalog) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('user_id')->inPath()->max(64)->describe('The member\'s user id.'),
            Field::string('role_id')->inPath()->max(190)->describe('The role id, or its manifest `key` with `client_id`.'),
            Field::string('client_id')->max(255)->describe('The app whose manifest `key` `role_id` is.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $member = MemberFields::find($organization->id, $context->string('user_id'));
        $role = RoleFields::find($context->string('role_id'), $context->nullableString('client_id'));

        // The ENVIRONMENT plane's set: this organization's own roles, the environment's
        // shared ones and the roles of the apps it can use — staff-only ones included.
        if (! $this->catalog->isAssignable($organization->id, $role->id)) {
            throw MemberFields::notAssignable($role);
        }

        MemberFields::grant($organization->id, $member->user_id, $role);

        return ActionResult::item($role, RoleAssignmentResource::from($role, $member->user_id, $organization->id, GrantSource::Manual));
    }
}
