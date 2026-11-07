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
use App\Platform\NativeProduct\NativeProductMembers;

/**
 * Take somebody off the workspace's team. Never the owner — ownership is transferred
 * first — and never the person acting ({@see ManageableMember}).
 */
#[AsAction(
    name: 'team.remove',
    summary: 'Remove native ID app roles while preserving shared organization membership and other apps. The current owner cannot be removed.',
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
        private NativeProductMembers $members,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath(), Field::string('roles_version')->required()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $target = ManageableMember::resolve($context->principal, $workspaceId, $context->string('id'));

        $this->members->replace($target, [], $context->string('roles_version'), $context->actor());

        return ActionResult::none($target);
    }
}
