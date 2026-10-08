<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Actions\Organizations\OrganizationFields;
use App\Actions\Roles\RoleFields;
use App\Http\Resources\Environment\RoleAssignmentResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\GrantAccessRole;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Enums\GrantSource;
use Cbox\Id\AccessControl\Exceptions\GrantRefused;
use Cbox\Id\AccessControl\Exceptions\UnknownRole;
use Cbox\Id\AccessControl\Models\Role;

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
 *
 * FROM INSIDE THE ORGANIZATION — its People page, or a token one of its administrators
 * signed in for — it is the TENANT's authority instead ({@see TenantRoster}): only the
 * roles the organization's own administrators may hand out
 * ({@see OrgAccessRoles::tenantAssignable()}), granted through
 * {@see GrantAccessRole::grantAsTenant()}, which refuses a staff role however it was named.
 * A staff role and one that does not exist are refused with the same sentence
 * (`role_not_assignable`), so the refusal says nothing about which roles exist.
 */
#[AsAction(
    name: 'members.roles.grant',
    summary: 'Grant a member an access role inside one organization, with the environment\'s authority (staff-only roles included) — or, from inside the organization, one its administrators may hand out. Name the role by id, or by manifest `key` with `client_id`.',
    scope: 'roles:write',
    danger: Danger::Write,
    schema: 'RoleAssignment',
    tag: 'Roles',
    rest: ['PUT', '/organizations/{organization_id}/members/{user_id}/roles/{role_id}'],
    consoleRoutes: ['environment.organizations.members.access', 'environment.users.organizations.access', 'directory.members.access'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class GrantMemberRole implements Action
{
    public function __construct(
        private OrgAccessRoles $catalog,
        private GrantAccessRole $grants,
    ) {}

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

        if (TenantRoster::confined($context) !== null) {
            $role = self::tenantRole($this->catalog, $organization->id, $context);

            try {
                $refusal = $this->grants->grantAsTenant($organization->id, $member->user_id, $role->id, GrantSource::Manual);
            } catch (GrantRefused $refused) {
                throw new ActionRefused('role_conflict', $refused->getMessage(), 409, 'role_id');
            } catch (UnknownRole) {
                throw ActionRefused::because('role_not_assignable', OrgAccessRoles::NOT_OFFERED, 'role_id');
            }

            if ($refusal !== null) {
                throw new ActionRefused('role_conflict', $refusal->message(), 409, 'role_id');
            }

            return ActionResult::item($role, RoleAssignmentResource::from($role, $member->user_id, $organization->id, GrantSource::Manual));
        }

        $role = RoleFields::find($context->string('role_id'), $context->nullableString('client_id'));

        // The ENVIRONMENT plane's set: this organization's own roles, the environment's
        // shared ones and the roles of the apps it can use — staff-only ones included.
        if (! $this->catalog->isAssignable($organization->id, $role->id)) {
            throw MemberFields::notAssignable($role);
        }

        MemberFields::grant($organization->id, $member->user_id, $role);

        return ActionResult::item($role, RoleAssignmentResource::from($role, $member->user_id, $organization->id, GrantSource::Manual));
    }

    /**
     * The role a person inside the organization named, if it is one its own administrators
     * may hand out — refused otherwise with the tenant plane's one sentence, whether it is a
     * staff role, another organization's, or no role at all.
     *
     * @throws ActionRefused
     */
    public static function tenantRole(OrgAccessRoles $catalog, string $organizationId, ActionContext $context): Role
    {
        $role = RoleFields::reference($context->string('role_id'), $context->nullableString('client_id'));

        if ($role === null || ! $catalog->isTenantAssignable($organizationId, $role->id)) {
            throw ActionRefused::because('role_not_assignable', OrgAccessRoles::NOT_OFFERED, 'role_id');
        }

        return $role;
    }
}
