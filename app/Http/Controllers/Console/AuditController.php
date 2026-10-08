<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\HelpProps;
use App\Http\Props\Shared\SimplePaginationProps;
use App\Platform\Actions\ActionTrail;
use App\Platform\Actions\ActionVia;
use App\Platform\Audit\AuditActorKind;
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
 * organization whenever one is resolved, and only the plane that legitimately holds
 * the environment ever sees the unscoped view. Entries are environment-owned, so even that
 * branch is bounded by the environment — an overview of what this administrator already
 * holds, never a window into another environment.
 */
final readonly class AuditController extends ConsoleController
{
    private const PER_PAGE = 25;

    /**
     * Context a person wrote rather than a system recorded, shown ahead of the ids.
     *
     * @var list<string>
     */
    private const FIRST_FACTS = ['reason'];

    public function index(Request $request, AuditNames $names): Response
    {
        $this->scope->assertMayAdminister();

        $filter = $this->organizationFilter();

        /*
         * Strictly this organization's own entries — the member's own, the one an
         * organization's Audit log tab names, or the one the environment-wide trail is
         * filtered to — not its entries PLUS the environment's, the way a rules list unions
         * in what binds it. An environment-level action is the control plane's own
         * business, and the whole point of this page is that one tenant's trail is not
         * another's.
         *
         * Unfiltered — only reachable by an administrator who holds the environment, see
         * {@see ConsoleController::organizationFilter()} — this is the environment's whole
         * trail, which is that console's existing and legitimate view. A filter naming no
         * organization here is an empty trail, never the whole one.
         */
        $query = $filter->apply(AuditEntry::query())
            ->orderByDesc('sequence');

        $action = trim($request->string('action')->toString());

        if ($action !== '') {
            $query->where('action', 'like', '%'.$action.'%');
        }

        // One entry by its id — where ⌘K sends a pasted audit id. Within the same bounds as
        // the rest of the page: another organization's entry is simply not found.
        $entry = trim($request->string('entry')->toString());

        if ($entry !== '') {
            $query->whereKey($entry);
        }

        $actor = AuditActorKind::tryFrom($request->string('actor')->toString());
        $actor?->constrain($query);

        $via = ActionVia::tryFrom($request->string('via')->toString());

        if ($via !== null) {
            $query->where('context->'.ActionTrail::VIA, $via->value);
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

        // Whose trail each row is, on the list that holds every organization's — one query
        // for the names this page shows.
        $owners = $filter->active() ? [] : $this->scope->organizationNames($entries->getCollection()->pluck('organization_id'));

        return $this->page('console/audit', 'Audit log', [
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
                'organization' => $entry->organization_id === null ? null : ($owners[$entry->organization_id] ?? null),
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
                    // The door and the approval have places of their own on the row.
                    ->except([ActionTrail::VIA, ActionTrail::APPROVAL, ActionTrail::APPROVED_BY])
                    ->filter(fn (mixed $value): bool => is_scalar($value))
                    // The facts a person wrote down first: a support session's reason is
                    // the only account the organization gets of it, and three ids ahead of
                    // it pushed it off the row.
                    ->sortBy(fn (mixed $value, string $key): int => in_array($key, self::FIRST_FACTS, true) ? 0 : 1)
                    ->take(3)
                    ->map(fn (mixed $value, string $key): string => $key.': '.(string) $value)
                    ->values()
                    ->all(),
                'actorKind' => AuditActorKind::of($entry)->value,
                'via' => ActionVia::tryFrom(self::contextString($entry, ActionTrail::VIA))?->label(),
                /*
                 * WHO SAID YES. An action an agent's key was held on ran because a person
                 * approved it, and the row said only that the key did it — which is true and
                 * leaves out the half an auditor is asking about.
                 */
                'approvedBy' => ($approver = self::contextString($entry, ActionTrail::APPROVED_BY)) === ''
                    ? null
                    : ($resolved[$approver] ?? $approver),
                // ISO, rendered relative in the browser: "3 minutes ago" computed on the
                // server is wrong the moment the page sits open.
                'recordedAt' => $entry->recorded_at?->toIso8601String(),
            ])->values()->all(),
            'pagination' => SimplePaginationProps::from($entries),
            'filters' => ['action' => $action, 'q' => $term, 'actor' => $actor?->value, 'via' => $via?->value],
            'actorKinds' => array_map(static fn (AuditActorKind $kind): array => ['value' => $kind->value, 'label' => $kind->label()], AuditActorKind::cases()),
            'doors' => array_map(static fn (ActionVia $door): array => ['value' => $door->value, 'label' => $door->label()], ActionVia::cases()),
            'environmentWide' => ! $filter->active(),
            'organizationFilter' => $this->organizationFilterProps($filter),
        ]);
    }

    private static function contextString(AuditEntry $entry, string $key): string
    {
        $value = $entry->context[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
