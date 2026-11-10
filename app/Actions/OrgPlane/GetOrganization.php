<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;

/**
 * The workspace behind the caller, and its per-project plans — the programmatic view of
 * the console's Billing page.
 *
 * The plan/allowance anchors on the PROJECT (one workspace can own several
 * independently-billed products), so the plan block is a list of projects each with its
 * own environment allowance — never a single workspace-level number, which would misreport
 * a customer running more than one product. It is billing data, so it is included only for
 * a caller whose role may read billing: a Developer/CI key gets the workspace's identity,
 * not its plan.
 */
#[AsAction(
    name: 'organization.get',
    summary: 'Get the current organization identity: its id, name and status. No operational or financial records.',
    scope: 'workspace:read',
    danger: Danger::Read,
    plane: ActionPlane::Organization,
    rest: ['GET', '/'],
    consoleGate: ConsoleGate::OrganizationMember,
    schema: 'Organization',
    tag: 'Organization',
)]
final readonly class GetOrganization implements Action
{
    public function __construct(
        private Organizations $organizations,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InOrganization::id($context->principal);

        // IN THE PLATFORM ROOT. `organizations` is environment-owned, and this plane
        // resolves no environment by design, so without pinning the scope the read finds
        // nothing and a valid key gets a 404 for its own workspace.
        $organization = $this->platformRoot->run(fn (): ?Organization => $this->organizations->find($workspaceId))
            ?? throw ActionRefused::notFound('organization');

        $payload = [
            'id' => $organization->id,
            'name' => $organization->name,
            'status' => $organization->status->value,
        ];

        return ActionResult::item($organization, $payload);
    }
}
