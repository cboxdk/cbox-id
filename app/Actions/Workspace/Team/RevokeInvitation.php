<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Team;

use App\Actions\Workspace\InWorkspace;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Invitations\Contracts\TeamInvitations;
use App\Platform\Invitations\Exceptions\InvitationRefused;

/**
 * Withdraw an invitation nobody accepted; its link stops working. One that is not pending
 * on this workspace is not found.
 */
#[AsAction(
    name: 'team.invitations.revoke',
    summary: 'Withdraw a pending team invitation; its link stops working.',
    scope: 'team:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Workspace,
    rest: ['DELETE', '/invitations/{id}'],
    status: 204,
    consoleRoutes: ['members.invitations.revoke'],
    consoleGate: ConsoleGate::ManageMembers,
    tag: 'Team',
)]
final readonly class RevokeInvitation implements Action
{
    public function __construct(private TeamInvitations $team) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        try {
            $this->team->revoke(InWorkspace::id($context->principal), $context->string('id'), InWorkspace::actor($context->principal));
        } catch (InvitationRefused $refused) {
            throw InvitationRefusal::of($refused);
        }

        return ActionResult::none();
    }
}
