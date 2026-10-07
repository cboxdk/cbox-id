<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Organizations\OrganizationFields;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\Exceptions\InvitationRefused;

/**
 * Withdraw a pending invitation. Its link stops working and the roles parked for it go with
 * it. One that is no longer pending — accepted, or already withdrawn — is `not_pending`.
 */
#[AsAction(
    name: 'invitations.revoke',
    summary: 'Withdraw a pending invitation: its link stops working and the roles parked for it are dropped.',
    scope: 'invitations:write',
    danger: Danger::Destructive,
    tag: 'Invitations',
    rest: ['DELETE', '/organizations/{organization_id}/invitations/{invitation_id}'],
    status: 204,
    consoleRoutes: ['environment.organizations.invitations.revoke'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class RevokeInvitation implements Action
{
    public function __construct(private OrganizationInvitations $invitations) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath()->max(64)->describe('The organization id.'),
            Field::string('invitation_id')->inPath()->max(64)->describe('The invitation id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organization = OrganizationFields::find($context, $context->string('organization_id'));
        $invitation = InvitationFields::find($organization->id, $context->string('invitation_id'));

        try {
            $this->invitations->revoke($organization->id, $invitation->id, $context->actor()->id);
        } catch (InvitationRefused $refused) {
            throw InvitationFields::refused($refused);
        }

        return ActionResult::none($invitation);
    }
}
