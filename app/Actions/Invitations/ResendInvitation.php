<?php

declare(strict_types=1);

namespace App\Actions\Invitations;

use App\Actions\Organizations\OrganizationFields;
use App\Models\InvitationContext;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Invitations\Exceptions\InvitationRefused;

/**
 * Mail a pending invitation again, on a FRESH link: the server keeps only a hash of the old
 * one, so there is nothing to re-send. The answer is the new invitation — its id replaces
 * the old one, which stops working. Throttled per invitation (`too_soon`).
 */
#[AsAction(
    name: 'invitations.resend',
    summary: 'Re-send a pending invitation on a fresh link. The answer is the new invitation; the old id and link stop working.',
    scope: 'invitations:write',
    danger: Danger::Write,
    schema: 'Invitation',
    tag: 'Invitations',
    rest: ['POST', '/organizations/{organization_id}/invitations/{invitation_id}/resend'],
    consoleRoutes: ['environment.organizations.invitations.resend'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ResendInvitation implements Action
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

        // A key is nobody, so the mail is signed as the first one was; a person signs it
        // with their own name.
        $name = $context->principal instanceof ConsoleSessionPrincipal ? null : InvitationContext::query()
            ->where('organization_id', $organization->id)
            ->where('invitation_id', $invitation->id)
            ->value('invited_by_name');

        try {
            $sent = $this->invitations->resend($organization->id, $invitation->id, InvitationFields::inviter($context, is_string($name) ? $name : null));
        } catch (InvitationRefused $refused) {
            throw InvitationFields::refused($refused);
        }

        return ActionResult::item($sent, InvitationFields::present($sent->invitation));
    }
}
