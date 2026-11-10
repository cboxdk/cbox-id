<?php

declare(strict_types=1);

namespace App\Actions\OrgPlane\Environments;

use App\Actions\OrgPlane\InOrganization;
use App\Actions\OrgPlane\PagesByNumber;
use App\Http\Resources\OrgPlane\EnvironmentResource;
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
    summary: 'List every environment the organization owns, across its projects, with each one\'s issuer.',
    scope: 'workspace:read',
    danger: Danger::Read,
    plane: ActionPlane::Organization,
    rest: ['GET', '/environments'],
    consoleGate: ConsoleGate::ReadEnvironments,
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

        $workspace = InOrganization::id($context->principal);
        $reachable = InOrganization::environmentIds($context->principal, $workspace);
        $environments = Environment::query()
            ->when($reachable !== null, fn ($query) => $query->whereIn('id', $reachable ?? []))
            ->whereIn('project_id', Project::query()->where('organization_id', InOrganization::id($context->principal))->pluck('id'))
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
