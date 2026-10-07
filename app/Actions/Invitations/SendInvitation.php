<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Organizations\OrganizationFields;
use App\Actions\Roles\RoleFields;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\Invitations\ValueObjects\NewInvitation;
use App\Platform\OrgAccessRoles;
use App\Platform\OrgRoles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * Invite somebody into an organization by address — through {@see OrganizationInvitations},
 * the service every invite form uses, so an invitation from an app's backend parks its
 * roles, carries its way back to the app and can be re-sent exactly like one from a console.
 * Nobody is added without consent: the person accepts from the mail.
 *
 * ACCESS ROLES ON AN INVITATION ARE THE TENANT PLANE'S. Accepting is the invitee's act
 * inside the organization, and the service grants parked roles as the tenant — so a
 * staff-only role can never ride in on one. Where a console's picker quietly leaves such a
 * role out, this refuses it (`role_not_assignable`): a caller that sent a role and got a 201
 * would reasonably believe it will be granted. Grant a staff role to the member once they
 * have joined.
 */
#[AsAction(
    name: 'invitations.send',
    summary: 'Invite someone into an organization by email, on a tier and with access roles (by id, or manifest `key` with `client_id`), optionally leading back to an app.',
    scope: 'invitations:write',
    danger: Danger::Write,
    schema: 'Invitation',
    tag: 'Invitations',
    rest: ['POST', '/organizations/{organization_id}/invitations'],
    status: 201,
    consoleRoutes: ['environment.organizations.invitations.store'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class SendInvitation implements Action
{
    public function __construct(
        private OrganizationInvitations $invitations,
        private OrgAccessRoles $catalog,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('email')->required()->format('email')->max(190),
            Field::string('role')->oneOf(array_map(static fn (MembershipRole $role): string => $role->value, OrgRoles::assignable()))->describe('The tier. Default `member`; ownership is never given by invitation.'),
            Field::list('roles', Field::string('role')->max(190))->max(50)->describe('Access roles granted on acceptance: ids, or manifest keys of `client_id`\'s app. Never a staff role.'),
            Field::string('client_id')->nullable()->max(255)->describe('The app the invitation comes from, and leads back to.'),
            Field::string('return_to')->nullable()->max(2048)->describe('Where the person lands after accepting — on one of that app\'s registered redirect-URI origins.'),
            Field::string('inviter_name')->nullable()->max(120)->describe('Who the mail says it is from. Default: the app\'s name, else the environment\'s.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $clientId = $context->nullableString('client_id');
        $clientId = $clientId === null ? null : trim($clientId);

        $offered = null;
        $roleIds = [];

        foreach (array_unique(array_filter($context->array('roles'), static fn (mixed $ref): bool => is_string($ref) && $ref !== '')) as $reference) {
            $role = RoleFields::reference((string) $reference, $clientId)
                ?? throw ActionRefused::because('unknown_role', "No role [{$reference}] exists in this environment.", 'roles');

            $offered ??= $this->catalog->tenantAssignable($organization->id)->pluck('id')->all();

            if (! in_array($role->id, $offered, true)) {
                throw ActionRefused::because('role_not_assignable', $this->notOffered($role), 'roles');
            }

            $roleIds[] = $role->id;
        }

        $returnTo = $context->nullableString('return_to');
        $inviterName = $context->nullableString('inviter_name');

        try {
            $sent = $this->invitations->send(new NewInvitation(
                organizationId: $organization->id,
                email: trim($context->string('email')),
                role: MembershipRole::tryFrom($context->string('role')) ?? MembershipRole::Member,
                inviter: InvitationFields::inviter($context, $inviterName === null ? null : trim($inviterName), $clientId),
                accessRoleIds: array_values(array_unique($roleIds)),
                clientId: $clientId,
                returnTo: $returnTo === null ? null : trim($returnTo),
            ));
        } catch (InvitationRefused $refused) {
            throw InvitationFields::refused($refused);
        }

        return ActionResult::item($sent, InvitationFields::present($sent->invitation));
    }

    private function notOffered(Role $role): string
    {
        return $role->tenant_assignable
            ? "The role [{$role->name}] is not one this organization can use."
            : "The role [{$role->name}] is a staff role. Staff roles are never granted by invitation — grant it to the member after they join.";
    }
}
