<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Keys;

use App\Actions\Workspace\InWorkspace;
use App\Actions\Workspace\PagesByNumber;
use App\Http\Resources\Workspace\KeyResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Platform\Models\OrganizationApiKey;

/**
 * The workspace's keys, newest first — revoked and expired ones included, as the audit
 * list — and never their values. Who may see them is who may mint them: a role that
 * manages the team.
 */
#[AsAction(
    name: 'keys.workspace.list',
    summary: 'List the workspace\'s keys, newest first, revoked ones included — names, roles, scopes and expiry, never their values.',
    scope: 'workspace:read',
    danger: Danger::Read,
    plane: ActionPlane::Workspace,
    rest: ['GET', '/keys'],
    consoleGate: ConsoleGate::ManageMembers,
    schema: 'WorkspaceKey',
    tag: 'Keys',
)]
final class ListWorkspaceKeys implements Action
{
    use PagesByNumber;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        [$limit, $page] = $this->pageOf($context);

        // ULIDs are monotonic, so ordering by id is newest-first AND deterministic even for
        // keys minted within the same clock tick.
        $keys = OrganizationApiKey::query()
            ->where('organization_id', InWorkspace::id($context->principal))
            ->orderByDesc('id')
            ->skip(($page - 1) * $limit)
            ->limit($limit + 1)
            ->get();

        $hasMore = $keys->count() > $limit;
        $visible = $keys->take($limit);

        return ActionResult::page(
            $visible,
            array_values($visible->map(static fn (OrganizationApiKey $key): array => KeyResource::workspace($key))->all()),
            $this->pageMeta($limit, $page, $hasMore),
        );
    }
}
