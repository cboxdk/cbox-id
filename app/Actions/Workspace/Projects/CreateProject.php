<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Projects;

use App\Actions\Workspace\Environments\CreateEnvironment;
use App\Actions\Workspace\InWorkspace;
use App\Http\Resources\Workspace\ProjectResource;
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
use Cbox\Id\Platform\TenantProvisioner;

/**
 * Stand up another independently-billed IdP product. This is what lets a customer run
 * "Product 1" and "Product 2" from one workspace; its environments are then created under
 * it ({@see CreateEnvironment}).
 *
 * `addProject()` re-reads the workspace under a lock and refuses a suspended customer; the
 * model is what its signature asks for, not the authorization.
 */
#[AsAction(
    name: 'projects.create',
    summary: 'Create a project: another independently-billed IdP product, with its own environment allowance.',
    scope: 'projects:write',
    danger: Danger::Write,
    plane: ActionPlane::Workspace,
    rest: ['POST', '/projects'],
    status: 201,
    consoleRoutes: ['projects.store'],
    consoleGate: ConsoleGate::ManageEnvironments,
    schema: 'Project',
    tag: 'Projects',
)]
final readonly class CreateProject implements Action
{
    public function __construct(
        private TenantProvisioner $provisioner,
        private Organizations $organizations,
        private PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('name')->required()->max(120),
            Field::integer('environment_limit')->min(1)->max(100)->describe('This project\'s plan environment allowance (default 2).'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);

        // IN THE PLATFORM ROOT: `organizations` is environment-owned and this plane pins no
        // environment, so an unscoped read finds nothing.
        $organization = $this->platformRoot->run(fn (): ?Organization => $this->organizations->find($workspaceId))
            ?? throw ActionRefused::notFound('organization');

        $limit = $context->has('environment_limit') && is_numeric($context->input['environment_limit'])
            ? (int) $context->input['environment_limit']
            : null;

        $project = $this->provisioner->addProject($organization, trim($context->string('name')), $limit);

        return ActionResult::item($project, ProjectResource::from($project));
    }
}
