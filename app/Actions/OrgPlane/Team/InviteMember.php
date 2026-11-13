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
use App\Platform\Actions\RatedByInput;
use App\Platform\Invitations\Exceptions\InvitationRefused;
use App\Platform\NativeProduct\NativeProductManifest;
use App\Platform\NativeProduct\NativeTeamInvitations as TeamInvitations;

/**
 * Invite somebody onto the workspace's team — through {@see TeamInvitations}, so the mail,
 * the refusals, the activity log and the accept link (set a password, signed in to the
 * console) are the same whoever sends it. Owner is never invited; ownership is transferred.
 */
#[AsAction(
    name: 'team.invite',
    summary: 'Invite somebody onto the organization\'s team with a role; they get a mail with a link to set a password.',
    scope: 'id:members:write',
    danger: Danger::Write,
    plane: ActionPlane::Organization,
    rest: ['POST', '/members'],
    status: 201,
    consoleRoutes: ['orgs.settings.members.invite'],
    consoleGate: ConsoleGate::ManageMembers,
    schema: 'TeamInvitationReceipt',
    tag: 'Team',
)]
final readonly class InviteMember implements Action, RatedByInput
{
    /**
     * Critical when it hands out more than reading ({@see RatedByInput}): an Editor or Admin
     * holds `id:write`, which is environment-administrator authority in every environment the
     * member reaches, and Billing spends money — the same standing persistence a role-bearing
     * key is, which `keys.workspace.create` already treats as Critical. Reader only stays a Write.
     */
    public function dangerFor(ActionContext $context): Danger
    {
        $roles = array_values(array_filter($context->array('roles'), 'is_string'));

        return array_diff($roles, ['viewer']) === [] ? Danger::Write : Danger::Critical;
    }

    public function __construct(private TeamInvitations $team) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('email')->required()->format('email')->max(190),
            Field::string('name')->nullable()->max(120),
            Field::list('roles', Field::string('role')->oneOf(array_keys(NativeProductManifest::ROLES)))->required(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InOrganization::id($context->principal);
        $email = trim($context->string('email'));
        $roles = array_values(array_filter($context->array('roles'), 'is_string'));

        try {
            $invitation = $this->team->send($workspaceId, $email, $roles, InOrganization::inviter($context->principal), InOrganization::actor($context->principal));
        } catch (InvitationRefused $refused) {
            throw InvitationRefusal::of($refused);
        }

        return ActionResult::item($invitation, [
            'id' => $invitation->id,
            'email' => $email,
            'roles' => $this->team->roles($invitation),
            'status' => 'invited',
        ]);
    }
}
