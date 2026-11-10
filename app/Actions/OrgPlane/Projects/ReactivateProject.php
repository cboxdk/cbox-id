<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane\Projects;

use App\Actions\OrgPlane\InOrganization;
use App\Http\Resources\OrgPlane\ProjectResource;
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
    plane: ActionPlane::Organization,
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
        $workspaceId = InOrganization::id($context->principal);
        $project = InOrganization::project($workspaceId, $context->string('id'));

        InOrganization::assertUnscoped($context->principal, $workspaceId);

        $this->projects->reactivateForOrganization($workspaceId, $project->id);

        $project->refresh();

        InOrganization::record($context->principal, $workspaceId, 'organization.project_reactivated', 'project', $project->id, ['name' => $project->name]);

        return ActionResult::item($project, ProjectResource::from($project));
    }
}
