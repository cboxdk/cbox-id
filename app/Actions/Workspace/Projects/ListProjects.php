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
    consoleGate: ConsoleGate::WorkspaceMember,
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
        $projects = $this->projects->forOrganization(InWorkspace::id($context->principal));

        return ActionResult::items($projects, array_values($projects->map(ProjectResource::from(...))->all()));
    }
}
