<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Projects;

use App\Actions\Workspace\InWorkspace;
use App\Http\Resources\Workspace\ProjectResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Platform\Contracts\Projects;

/** Reactivate a suspended project, so environments can be added to it again. */
#[AsAction(
    name: 'projects.reactivate',
    summary: 'Reactivate a suspended project, so environments can be added to it again.',
    scope: 'projects:write',
    danger: Danger::Write,
    plane: ActionPlane::Workspace,
    rest: ['POST', '/projects/{id}/reactivate'],
    consoleRoutes: ['projects.reactivate'],
    consoleGate: ConsoleGate::ManageEnvironments,
    schema: 'Project',
    tag: 'Projects',
)]
final readonly class ReactivateProject implements Action
{
    public function __construct(private Projects $projects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('id')->inPath()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $project = InWorkspace::project($workspaceId, $context->string('id'));

        InWorkspace::assertUnscoped($context->principal, $workspaceId);

        $this->projects->reactivateForOrganization($workspaceId, $project->id);

        $project->refresh();

        InWorkspace::record($context->principal, $workspaceId, 'organization.project_reactivated', 'project', $project->id, ['name' => $project->name]);

        return ActionResult::item($project, ProjectResource::from($project));
    }
}
