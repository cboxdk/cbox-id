<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Team;

use App\Actions\Workspace\InWorkspace;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
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
    summary: 'Make another member the workspace\'s owner; the current owner stays on as an admin. Only the owner, signed in, may do this.',
    scope: 'team:write',
    danger: Danger::Critical,
    plane: ActionPlane::Workspace,
    rest: ['POST', '/members/{id}/transfer-ownership'],
    status: 204,
    consoleRoutes: ['members.transfer-ownership'],
    consoleGate: ConsoleGate::ManageMembers,
    tag: 'Members',
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

        if (! $principal instanceof ConsoleSessionPrincipal || $ownerId === null || $principal->scope()->membershipRole() !== MembershipRole::Owner) {
            throw new ActionRefused('owner_only', 'Only the workspace\'s owner can hand it over, signed in to the console.', 403);
        }

        $workspaceId = InWorkspace::id($principal);
        $target = InWorkspace::member($workspaceId, $context->string('id'));

        if ($target->user_id === $ownerId) {
            throw ActionRefused::because('already_owner', 'You already own this workspace.');
        }

        try {
            $this->platformRoot->run(
                fn () => $this->lifecycle->transferOwnership($workspaceId, $target->user_id, $ownerId, $ownerId),
            );
        } catch (MembershipRefused $refused) {
            throw ActionRefused::because($refused->reason->value, $refused->getMessage());
        }

        return ActionResult::none($target);
    }
}
