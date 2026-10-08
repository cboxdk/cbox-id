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
use Cbox\Id\Organization\Enums\MembershipRole;

/**
 * Invite somebody onto the workspace's team — through {@see TeamInvitations}, so the mail,
 * the refusals, the activity log and the accept link (set a password, signed in to the
 * console) are the same whoever sends it. Owner is never invited; ownership is transferred.
 */
#[AsAction(
    name: 'team.invite',
    summary: 'Invite somebody onto the workspace\'s team with a role; they get a mail with a link to set a password.',
    scope: 'team:write',
    danger: Danger::Write,
    plane: ActionPlane::Workspace,
    rest: ['POST', '/members'],
    status: 201,
    consoleRoutes: ['members.invite'],
    consoleGate: ConsoleGate::ManageMembers,
    schema: 'Member',
    tag: 'Team',
)]
final readonly class InviteMember implements Action
{
    public function __construct(private TeamInvitations $team) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('email')->required()->format('email')->max(190),
            Field::string('name')->nullable()->max(120),
            Field::string('role')->required()->oneOf(self::roles()),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $email = trim($context->string('email'));
        $role = MembershipRole::tryFrom($context->string('role')) ?? MembershipRole::Viewer;

        try {
            $invitation = $this->team->send($workspaceId, $email, $role, InWorkspace::inviter($context->principal), InWorkspace::actor($context->principal));
        } catch (InvitationRefused $refused) {
            throw InvitationRefusal::of($refused);
        }

        return ActionResult::item($invitation, [
            'id' => $invitation->id,
            'email' => $email,
            'role' => $role->value,
            'status' => 'invited',
        ]);
    }

    /** @return list<string> */
    private static function roles(): array
    {
        return array_map(static fn (MembershipRole $role): string => $role->value, MembershipRole::assignable());
    }
}
