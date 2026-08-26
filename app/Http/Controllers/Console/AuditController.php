<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\SimplePaginationProps;
use App\Platform\AuditNames;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * CONSOLE › ACTIVITY LOG — the append-only, hash-chained record of every
 * security-relevant action, newest first. One page, both planes.
 *
 * This was two pages that read the same table and disagreed about who may read WHICH
 * ROWS. The organization page filtered to the reader's own organization; the environment
 * page filtered to nothing at all, because the only caller was an administrator who held
 * the whole environment. Serving that second query to an organization administrator would
 * hand them every other tenant's trail — in the one feature whose entire purpose is to be
 * the record an auditor reads.
 *
 * So the scoping is the first thing here and not an afterthought: rows are bounded by the
 * acting organization whenever one is resolved, and only the plane that legitimately holds
 * the environment ever sees the unscoped view. Entries are environment-owned, so even that
 * branch is bounded by the environment — an overview of what this administrator already
 * holds, never a window into another environment.
 */
final readonly class AuditController extends ConsoleController
{
    private const PER_PAGE = 25;

    public function index(Request $request, AuditNames $names): Response
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->actingOrganizationId();

        $query = AuditEntry::query()
            /*
             * Strictly this organization's own entries when one is resolved — not its
             * entries PLUS the environment's, the way a rules list unions in what binds
             * it. An environment-level action is the control plane's own business, and
             * the whole point of this page is that one tenant's trail is not another's.
             *
             * With none resolved — only reachable by an administrator who holds the
             * environment, see {@see ConsoleController::actingOrganizationId()} — this is
             * the environment's whole trail, which is that console's existing and
             * legitimate view.
             */
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->orderByDesc('sequence');

        $action = trim($request->string('action')->toString());

        if ($action !== '') {
            $query->where('action', 'like', '%'.$action.'%');
        }

        $term = trim($request->string('q')->toString());

        if ($term !== '') {
            $query->where(fn (Builder $q): Builder => $q
                ->where('action', 'like', '%'.$term.'%')
                ->orWhere('target_type', 'like', '%'.$term.'%'));
        }

        /*
         * simplePaginate, not paginate: `paginate()` runs a COUNT(*) over the filtered set
         * on every render, and this table has no retention — it only grows — so that count
         * is a full index scan of the environment's whole audit partition just to render
         * page numbers nobody uses on an append-only feed. A next/previous cursor answers
         * the same question with one LIMIT n+1 read.
         */
        $entries = $query->simplePaginate(self::PER_PAGE)->withQueryString();

        // Resolved ONCE per page, in three queries — never per row.
        $resolved = $names->for($entries->getCollection());

        return $this->page('console/audit', 'Activity log', [
            'help' => HelpProps::for(HelpTopic::ActivityLog),
            'entries' => $entries->getCollection()->map(fn (AuditEntry $entry): array => [
                'id' => $entry->id,
                'sequence' => $entry->sequence,
                'action' => $entry->action,
                // Both consoles' renderings. The readable phrase answers "what happened";
                // the exact dotted action is what somebody greps for, quotes in a ticket
                // or types into the filter — and the environment console only ever showed
                // that second one.
                'phrase' => str_replace(['.', '_'], [' · ', ' '], $entry->action),
                'actorId' => $entry->actor_id,
                'actorName' => $entry->actor_id === null ? null : ($resolved[$entry->actor_id] ?? null),
                'actorType' => ucfirst($entry->actor_type->value),
                'targetId' => $entry->target_id,
                'targetName' => $entry->target_id === null ? null : ($resolved[$entry->target_id] ?? null),
                'targetType' => $entry->target_type === null ? null : str_replace('_', ' ', $entry->target_type),
                /*
                 * THE CONTEXT THE WRITER RECORDED. It was stored and never shown — an id
                 * tells you WHICH environment was created, and "Staging" tells you which
                 * one that was at the time, which is the whole reason somebody wrote it
                 * down. Scalars only: a nested payload belongs in an export, not in a
                 * table cell, and flattening one here produces a row nobody can read.
                 */
                'facts' => collect($entry->context)
                    ->filter(fn (mixed $value): bool => is_scalar($value))
                    ->take(3)
                    ->map(fn (mixed $value, string $key): string => $key.': '.(string) $value)
                    ->values()
                    ->all(),
                // ISO, rendered relative in the browser: "3 minutes ago" computed on the
                // server is wrong the moment the page sits open.
                'recordedAt' => $entry->recorded_at?->toIso8601String(),
            ])->values()->all(),
            'pagination' => SimplePaginationProps::from($entries),
            'filters' => ['action' => $action, 'q' => $term],
            'environmentWide' => $organizationId === null,
        ]);
    }
}
