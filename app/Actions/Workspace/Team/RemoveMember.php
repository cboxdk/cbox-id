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
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Take somebody off the workspace's team. Never the owner — ownership is transferred
 * first — and never the person acting ({@see ManageableMember}).
 */
#[AsAction(
    name: 'team.remove',
    summary: 'Remove a member from the workspace\'s team. The owner is never removed; transfer ownership first.',
    scope: 'team:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Workspace,
    rest: ['DELETE', '/members/{id}'],
    status: 204,
    consoleRoutes: ['members.remove'],
    consoleGate: ConsoleGate::ManageMembers,
    tag: 'Team',
)]
final readonly class RemoveMember implements Action
{
    public function __construct(
        private Memberships $members,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $target = ManageableMember::resolve($context->principal, $workspaceId, $context->string('id'));

        $this->platformRoot->run(fn () => $this->members->remove($workspaceId, $target->user_id));

        InWorkspace::record($context->principal, $workspaceId, 'organization.member_removed', 'membership', $target->id);

        return ActionResult::none($target);
    }
}
