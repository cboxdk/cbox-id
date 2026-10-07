<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Team;

use App\Actions\Workspace\InWorkspace;
use App\Actions\Workspace\PagesByNumber;
use App\Http\Resources\Workspace\MemberResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Pagination\PaginationState;
use Illuminate\Pagination\Paginator;

/**
 * The workspace's team. The roster is PII, so it needs `read-members` — owners, admins and
 * the read-only Viewer; never a Developer, which is frequently a CI or agent credential.
 *
 * PAGINATED IN SQL, not sliced in PHP, and the people behind a page are read in ONE query
 * (`findMany`), not one per row. Both run in the platform root — memberships and subjects
 * are environment-owned, and this plane pins no environment.
 */
#[AsAction(
    name: 'team.list',
    summary: 'List the workspace\'s team: each member\'s name, address, role and whether they reach every environment.',
    scope: 'team:read',
    danger: Danger::Read,
    plane: ActionPlane::Workspace,
    rest: ['GET', '/members'],
    consoleGate: ConsoleGate::ReadMembers,
    schema: 'Member',
    tag: 'Members',
)]
final class ListMembers implements Action
{
    use PagesByNumber;

    public function __construct(
        private readonly Memberships $members,
        private readonly Subjects $subjects,
        private readonly PlatformRoot $platformRoot,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        [$limit, $page] = $this->pageOf($context);
        $workspaceId = InWorkspace::id($context->principal);

        /** @var array{0: int, 1: bool, 2: list<array<string, mixed>>} $result */
        $result = $this->platformRoot->run(function () use ($workspaceId, $limit, $page): array {
            // The framework's paginator reads its page from a resolver that defaults to the
            // HTTP request; an action's page is its INPUT, whichever door it came through,
            // so the resolver answers from that for this one read and is put back after.
            Paginator::currentPageResolver(static fn (): int => $page);

            try {
                $paginator = $this->members->paginateForOrganization($workspaceId, $limit);
            } finally {
                PaginationState::resolveUsing(app());
            }

            /** @var list<Membership> $rows */
            $rows = array_values($paginator->items());

            $people = $this->subjects->findMany(array_map(
                static fn (Membership $membership): string => $membership->user_id,
                $rows,
            ));

            return [
                $paginator->total(),
                $paginator->hasMorePages(),
                array_map(
                    static fn (Membership $membership): array => MemberResource::from($membership, $people[$membership->user_id] ?? null),
                    $rows,
                ),
            ];
        }) ?? [0, false, []];

        [$total, $hasMore, $rows] = $result;

        return ActionResult::page($rows, $rows, $this->pageMeta($limit, $page, $hasMore, $total));
    }
}
