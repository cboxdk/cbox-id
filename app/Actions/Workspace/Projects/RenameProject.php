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

/**
 * Rename a project.
 *
 * Through the OWNER-CARRYING verb: `Project` has no global scope, so a bare
 * `rename($id, …)` is a write across every customer's projects, fenced only by the caller
 * remembering to resolve first. It is resolved first ({@see InWorkspace::project()}) — and
 * the query is fenced too.
 */
#[AsAction(
    name: 'projects.rename',
    summary: 'Rename a project.',
    scope: 'projects:write',
    danger: Danger::Write,
    plane: ActionPlane::Workspace,
    rest: ['PATCH', '/projects/{id}'],
    consoleRoutes: ['projects.rename'],
    consoleGate: ConsoleGate::ManageEnvironments,
    schema: 'Project',
    tag: 'Projects',
)]
final readonly class RenameProject implements Action
{
    public function __construct(private Projects $projects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath(),
            Field::string('name')->required()->max(120),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $project = InWorkspace::project($workspaceId, $context->string('id'));

        InWorkspace::assertUnscoped($context->principal, $workspaceId);

        $from = $project->name;
        $this->projects->renameForOrganization($workspaceId, $project->id, trim($context->string('name')));

        $project->refresh();

        InWorkspace::record($context->principal, $workspaceId, 'organization.project_renamed', 'project', $project->id, ['from' => $from, 'name' => $project->name]);

        return ActionResult::item($project, ProjectResource::from($project));
    }
}
