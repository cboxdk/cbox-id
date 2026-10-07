<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Environments;

use App\Actions\Workspace\InWorkspace;
use App\Actions\Workspace\PagesByNumber;
use App\Http\Resources\Workspace\EnvironmentResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;

/**
 * Every environment the workspace owns, across its projects, oldest first.
 *
 * `limit + 1` rows are read to learn whether there is a next page without a second COUNT —
 * the extra row is read and dropped.
 */
#[AsAction(
    name: 'environments.list',
    summary: 'List every environment the workspace owns, across its projects, with each one\'s issuer.',
    scope: 'workspace:read',
    danger: Danger::Read,
    plane: ActionPlane::Workspace,
    rest: ['GET', '/environments'],
    consoleGate: ConsoleGate::WorkspaceMember,
    schema: 'Environment',
    tag: 'Environments',
)]
final class ListEnvironments implements Action
{
    use PagesByNumber;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        [$limit, $page] = $this->pageOf($context);

        $environments = Environment::query()
            ->whereIn('project_id', Project::query()->where('organization_id', InWorkspace::id($context->principal))->pluck('id'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->skip(($page - 1) * $limit)
            ->limit($limit + 1)
            ->get();

        $hasMore = $environments->count() > $limit;
        $visible = $environments->take($limit);

        return ActionResult::page(
            $visible,
            array_values($visible->map(EnvironmentResource::from(...))->all()),
            $this->pageMeta($limit, $page, $hasMore),
        );
    }
}
