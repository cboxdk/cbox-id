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
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Contracts\OrganizationProjects;

/**
 * The workspace's projects (IdP products), each with its own plan and environment
 * allowance. Not paged: a workspace owns a handful, bounded by what it buys.
 */
#[AsAction(
    name: 'projects.list',
    summary: 'List the workspace\'s projects (IdP products), each with its environment allowance and how much of it is used.',
    scope: 'workspace:read',
    danger: Danger::Read,
    plane: ActionPlane::Workspace,
    rest: ['GET', '/projects'],
    consoleGate: ConsoleGate::ReadEnvironments,
    schema: 'Project',
    tag: 'Projects',
)]
final readonly class ListProjects implements Action
{
    public function __construct(private OrganizationProjects $projects) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspace = InWorkspace::id($context->principal);
        $projects = $this->projects->forOrganization($workspace);
        if (! InWorkspace::unscoped($context->principal, $workspace)) {
            $ids = Environment::query()->whereIn('id', InWorkspace::environmentIds($context->principal, $workspace) ?? [])->pluck('project_id')->all();
            $projects = $projects->filter(static fn ($project): bool => in_array($project->id, $ids, true))->values();
        }

        $visible = InWorkspace::environmentIds($context->principal, $workspace);

        return ActionResult::items($projects, array_values($projects->map(static function ($project) use ($visible): array {
            $payload = ProjectResource::from($project);
            if ($visible !== null) {
                $payload['environments_used'] = Environment::query()->where('project_id', $project->id)->whereIn('id', $visible)->count();
            }

            return $payload;
        })->all()));
    }
}
