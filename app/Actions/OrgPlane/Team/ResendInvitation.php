<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane\Team;

use App\Actions\OrgPlane\InOrganization;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\NativeProduct\NativeTeamInvitations as TeamInvitations;

/**
 * Send a pending invitation again, with a fresh link — the earlier one stops working and
 * the invitation gets a new id. At most once a minute per address (`too_soon`); a mail
 * server that refuses keeps the invitation (`mail_failed`).
 */
#[AsAction(
    name: 'team.invitations.resend',
    summary: 'Re-send a pending team invitation with a fresh link; the earlier link stops working and the invitation gets a new id.',
    scope: 'id:members:write',
    danger: Danger::Write,
    plane: ActionPlane::Organization,
    rest: ['POST', '/invitations/{id}/resend'],
    consoleRoutes: ['orgs.settings.members.invitations.resend'],
    consoleGate: ConsoleGate::ManageMembers,
    schema: 'TeamInvitationReceipt',
    tag: 'Team',
)]
final readonly class ResendInvitation implements Action
{
    public function __construct(private TeamInvitations $team) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        try {
            $sent = $this->team->resend(
                InOrganization::id($context->principal),
                $context->string('id'),
                InOrganization::inviter($context->principal),
                InOrganization::actor($context->principal),
            );
        } catch (InvitationRefused $refused) {
            throw InvitationRefusal::of($refused);
        }

        return ActionResult::item($sent, [
            'id' => $sent->id,
            'email' => $sent->email,
            'roles' => $this->team->roles($sent),
            'status' => 'invited',
        ]);
    }
}
