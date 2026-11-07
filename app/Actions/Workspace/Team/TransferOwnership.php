<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Team;

use App\Actions\Workspace\InWorkspace;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionMessage;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Membership\MembershipLifecycle;
use App\Platform\Membership\MembershipRefused;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Hand the workspace to another member. The OWNER's act and nobody else's: the current
 * owner, signed in, promotes the other member and steps down to admin, in one transaction
 * ({@see MembershipLifecycle::transferOwnership()}, which records it as the owner's act).
 *
 * A key never holds the owner role — keys are minted with an assignable role, and Owner is
 * not one — so from the API this is refused (`owner_only`) whatever the key's scopes. It
 * is an action all the same, so the console and any later door run the same rule; giving a
 * machine a way to hand the company away is not something a scope should be able to grant.
 */
#[AsAction(
    name: 'team.transfer_ownership',
    summary: 'Transfer shared organization ownership. Existing app roles are preserved; the previous owner may lose native ID access. Only the current owner, signed in, may do this.',
    scope: 'team:write',
    danger: Danger::Critical,
    plane: ActionPlane::Workspace,
    rest: ['POST', '/members/{id}/transfer-ownership'],
    status: 204,
    consoleRoutes: ['members.transfer-ownership'],
    consoleGate: ConsoleGate::ManageMembers,
    tag: 'Team',
)]
final readonly class TransferOwnership implements Action
{
    public function __construct(
        private MembershipLifecycle $lifecycle,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $principal = $context->principal;
        $ownerId = InWorkspace::personId($principal);

        // The member first, inside the workspace: another workspace's member answers 404
        // like any unknown id, before anything is said about who may transfer.
        $workspaceId = InWorkspace::id($principal);
        $target = InWorkspace::member($workspaceId, $context->string('id'));

        if (! $principal instanceof ConsoleSessionPrincipal || $ownerId === null || $principal->scope()->membershipRole() !== MembershipRole::Owner) {
            throw new ActionRefused('owner_only', new ActionMessage('console.only_the_workspace_s_owner_can_hand_it_over_signed_in_t_eae1f61a'), 403);
        }

        if ($target->user_id === $ownerId) {
            throw ActionRefused::because('already_owner', new ActionMessage('console.you_already_own_this_workspace_81bb6c42'));
        }

        try {
            $this->platformRoot->run(
                fn () => $this->lifecycle->transferOwnership($workspaceId, $target->user_id, $ownerId, $ownerId),
            );
        } catch (MembershipRefused $refused) {
            throw ActionRefused::because($refused->reason->value, $refused->sourceMessage());
        }

        return ActionResult::none($target);
    }
}
