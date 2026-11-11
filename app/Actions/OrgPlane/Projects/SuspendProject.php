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

/**
 * Suspend a project: its environments stay live, but no new one can be added until it is
 * reactivated. A write, not a destruction — {@see ReactivateProject} undoes it.
 */
#[AsAction(
    name: 'projects.suspend',
    summary: 'Suspend a project: its environments stay live, but no new ones can be added until it is reactivated.',
    scope: 'id:projects:write',
    danger: Danger::Write,
    plane: ActionPlane::Organization,
    rest: ['POST', '/projects/{id}/suspend'],
    consoleRoutes: ['orgs.projects.suspend'],
    consoleGate: ConsoleGate::ManageEnvironments,
    schema: 'Project',
    tag: 'Projects',
)]
final readonly class SuspendProject implements Action
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

        $this->projects->suspendForOrganization($workspaceId, $project->id);

        $project->refresh();

        InOrganization::record($context->principal, $workspaceId, 'organization.project_suspended', 'project', $project->id, ['name' => $project->name]);

        return ActionResult::item($project, ProjectResource::from($project));
    }
}
