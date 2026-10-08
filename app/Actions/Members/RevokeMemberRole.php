<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Actions\Organizations\OrganizationFields;
use App\Actions\Roles\RoleFields;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\GrantAccessRole;
use App\Platform\OrgAccessRoles;

/**
 * Take an access role back from a member inside one organization. Revoking never conflicts
 * — a conflict is a pair being HELD — and is idempotent: taking back one the member does
 * not hold is a 204 too. An orphaned role (its app stopped declaring it) can still be taken
 * back.
 *
 * The console's per-role checkbox reaches this through the grant's route: one explicit
 * set, granted or not, rather than a toggle a retried request would flip back.
 *
 * FROM INSIDE THE ORGANIZATION only the roles its own administrators may hand out can be
 * taken back there ({@see GrantMemberRole::tenantRole()}) — a staff role an environment
 * administrator granted is theirs to withdraw.
 */
#[AsAction(
    name: 'members.roles.revoke',
    summary: 'Take an access role back from a member inside one organization. Name the role by id, or by manifest `key` with `client_id`.',
    scope: 'roles:write',
    danger: Danger::Destructive,
    tag: 'Roles',
    rest: ['DELETE', '/organizations/{organization_id}/members/{user_id}/roles/{role_id}'],
    status: 204,
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RevokeMemberRole implements Action
{
    public function __construct(
        private GrantAccessRole $grants,
        private OrgAccessRoles $catalog,
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
        $role = TenantRoster::confined($context) !== null
            ? GrantMemberRole::tenantRole($this->catalog, $organization->id, $context)
            : RoleFields::find($context->string('role_id'), $context->nullableString('client_id'), orphaned: true);

        $this->grants->revoke($organization->id, $member->user_id, $role->id);

        return ActionResult::none($role);
    }
}
