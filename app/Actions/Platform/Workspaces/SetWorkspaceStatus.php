<?php

declare(strict_types=1);

namespace App\Actions\Platform\Workspaces;

use App\Actions\Platform\AsOperator;
use App\Actions\Platform\PlatformOrganizationFields;
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
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;

/**
 * Suspend a customer workspace, or reactivate it — the platform's off-switch for a
 * customer. Suspended, its people can no longer sign in and its environments stop serving.
 *
 * THE STATE, NOT A TOGGLE. The console's button flips whatever it finds; over an API a
 * flip is the wrong primitive, because a retried "toggle" undoes itself. So the caller
 * names the state it wants, asking for the state it already has changes nothing and
 * records nothing, and the console's button asks for the opposite of what it shows.
 *
 * A workspace is an organization in the PLATFORM ROOT, so it is looked up and changed
 * there: from any other scope a suspension would find nothing to suspend. The change is
 * recorded by {@see Organizations::suspend()} itself, as the acting operator.
 */
#[AsAction(
    name: 'platform.workspaces.set_status',
    summary: 'Suspend a workspace (its people can no longer sign in, its environments stop serving) or reactivate it.',
    scope: 'operator:workspaces:write',
    danger: Danger::Critical,
    plane: ActionPlane::Platform,
    rest: ['PUT', '/workspaces/{workspace_id}/status'],
    consoleRoutes: ['platform.workspaces.toggle'],
    consoleGate: ConsoleGate::Operator,
    schema: 'PlatformOrganization',
    tag: 'Workspaces',
)]
final readonly class SetWorkspaceStatus implements Action
{
    public function __construct(
        private Organizations $organizations,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('workspace_id')->inPath(),
            Field::string('status')->required()->oneOf(['active', 'suspended']),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $operatorId = AsOperator::id($context->principal);
        $suspend = $context->string('status') === 'suspended';
        $id = $context->string('workspace_id');

        $workspace = $this->platformRoot->run(function () use ($id, $suspend, $operatorId): ?Organization {
            $workspace = $this->organizations->find($id);

            if ($workspace === null) {
                return null;
            }

            if ($suspend === ! $workspace->status->revokesAccess()) {
                $workspace = $suspend
                    ? $this->organizations->suspend($workspace->id, $operatorId)
                    : $this->organizations->reactivate($workspace->id, $operatorId);
            }

            return $workspace;
        }) ?? throw ActionRefused::notFound('workspace');

        return ActionResult::item($workspace, PlatformOrganizationFields::present($workspace));
    }
}
