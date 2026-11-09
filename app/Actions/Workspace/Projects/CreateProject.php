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
use App\Platform\Actions\Principal\SignedInPerson;
use App\Platform\Billing\ProductBilling;
use App\Platform\ServiceAccess\ProductAdmission;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Illuminate\Support\Facades\DB;

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
            Field::integer('environment_limit')->min(1)->max(100)->describe('Optional lower allowance; it cannot exceed your account access.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        app(ProductAdmission::class)->assertGranted($context->principal instanceof SignedInPerson ? $context->principal->subjectId() : $context->principal->id(), $workspaceId, acting: true);

        // IN THE PLATFORM ROOT: `organizations` is environment-owned and this plane pins no
        // environment, so an unscoped read finds nothing.
        $organization = $this->platformRoot->run(fn (): ?Organization => $this->organizations->find($workspaceId))
            ?? throw ActionRefused::notFound('organization');

        $limit = $context->has('environment_limit') && is_numeric($context->input['environment_limit'])
            ? (int) $context->input['environment_limit']
            : null;

        $project = DB::transaction(function () use ($organization, $workspaceId, $context, $limit) {
            DB::table('organizations')->where('id', $workspaceId)->lockForUpdate()->first();
            $ceiling = app(ProductBilling::class)->environmentLimit($workspaceId);
            if ($ceiling < 1 || ($limit !== null && $limit > $ceiling)) {
                throw ActionRefused::because('environment_limit_reached', __('billing.allowance_exceeded'), 'environment_limit');
            }

            return $this->provisioner->addProject($organization, trim($context->string('name')), $limit ?? $ceiling);
        });

        // On the workspace's log, like the environments created under it: a project is a
        // billing anchor, and adding one recorded nothing at all.
        InWorkspace::record($context->principal, $workspaceId, 'organization.project_created', 'project', $project->id, [
            'name' => $project->name,
            'environment_limit' => $project->environment_limit,
        ]);

        return ActionResult::item($project, ProjectResource::from($project));
    }
}
