<?php

declare(strict_types=1);

use App\Platform\AuditNames;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Console › Activity log — one component, both planes. The append-only, hash-chained
 * record of every security-relevant action, newest first.
 *
 * This was two pages that read the same table and disagreed about who may read WHICH
 * ROWS. The organization page filtered to the reader's own organization; the environment
 * page filtered to nothing at all, because the only caller was an administrator who held
 * the whole environment. Serving that second query to an organization admin would have
 * handed them every other tenant's trail — in the one feature whose entire purpose is to
 * be the record an auditor reads. So the scoping is the first thing here, not an
 * afterthought: rows are bounded by the acting organization whenever one is resolved, and
 * only the plane that legitimately holds the environment ever sees the unscoped view.
 *
 * Entries are environment-owned (BelongsToEnvironment), so even that unscoped branch is
 * bounded by the environment — it is an overview of what this administrator already
 * holds, never a window into another environment.
 */
new #[Layout('components.layouts.console', ['title' => 'Activity log'])] class extends Component
{
    use WithPagination;

    /**
     * The organization plane's control: actions only, and precise.
     *
     * URL-bound like the search below, which it was not before — a filtered audit view is
     * exactly the thing someone pastes into a ticket.
     */
    #[Url(as: 'action')]
    public string $actionFilter = '';

    /** The environment plane's control: broader, matching the target type as well. */
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     *
     * On the organization plane this is the membership role: the log names every actor,
     * target and action in the organization, which is an admin surface and not a
     * member's.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public function updatingActionFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $organizationId = $this->actingOrganizationId();

        $query = AuditEntry::query()
            // Strictly this organization's own entries when one is resolved — not its
            // entries PLUS the environment's, the way a rules list unions in what binds
            // it. An environment-level action is the control plane's own business, and
            // the whole point of this page is that one tenant's trail is not another's.
            //
            // With none resolved — only reachable by an administrator who holds the
            // environment, see actingOrganizationId() — this is the environment's whole
            // trail, which is the environment console's existing and legitimate view.
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->orderByDesc('sequence');

        $action = trim($this->actionFilter);
        if ($action !== '') {
            $query->where('action', 'like', '%'.$action.'%');
        }

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(fn (Builder $q): Builder => $q->where('action', 'like', "%{$term}%")->orWhere('target_type', 'like', "%{$term}%"));
        }

        // simplePaginate, not paginate: `paginate()` runs a COUNT(*) over the filtered
        // set on every render, and this table has no retention — it only grows — so
        // that count is a full index scan of the environment's whole audit partition
        // just to render page numbers nobody uses on an append-only feed. A
        // next/previous cursor answers the same question with one LIMIT n+1 read.
        $entries = $query->simplePaginate(25);

        return [
            'entries' => $entries,
            // The organization console's name resolution, now on both planes. Resolved
            // once per page, in three queries — never per row.
            'names' => app(AuditNames::class)->for($entries->getCollection()),
            'environmentWide' => $organizationId === null,
        ];
    }

    /**
     * The organization whose trail this page is about, or null for the whole-environment
     * view.
     *
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and on the
     * organization plane that second case would widen the page from one organization's
     * audit trail to every organization's.
     */
    private function actingOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Environment
            ? $scope->organizationId()
            : $scope->requireOrganizationId();
    }
}; ?>

<div>
    <x-page-header title="Activity log" class="flex-wrap" :help="\App\Platform\Help\HelpTopic::ActivityLog"
                   subtitle="Every change made here: who did what, to what, and when. Hash-chained, so a removed entry shows up.">
        <x-slot:actions>
            <div class="relative w-full sm:w-auto">
                <input wire:model.live.debounce.300ms="actionFilter" type="text" class="input w-full sm:min-w-[16rem]" placeholder="Filter by action…" aria-label="Filter by action">
            </div>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search action or target" aria-label="Search the activity log">
        {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
             change, so the result count is the only thing that can report the filter
             narrowed to nothing. Counted on THIS page rather than across the whole
             filtered set — the total is what the removed COUNT(*) was paying for, and
             "no entries on this page" reports the empty filter just as clearly. --}}
        <p role="status" aria-live="polite" class="sr-only">{{ $entries->count() }} {{ \Illuminate\Support\Str::plural('entry', $entries->count()) }} on this page.</p>
        @if ($environmentWide)
            <p class="mt-2 text-xs" style="color:var(--faint)">Every organization in this environment. Choose one above to narrow the trail to it.</p>
        @endif
    </div>

    <div class="card overflow-hidden mt-4">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:1%" class="hidden sm:table-cell">Seq</th>
                        <th scope="col">Action</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Target</th>
                        <th class="text-right">When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr wire:key="entry-{{ $entry->id }}">
                            <td class="mono text-xs hidden sm:table-cell" style="color:var(--faint)">{{ $entry->sequence }}</td>
                            {{-- Both consoles' renderings, in the pattern this table
                                 already uses for actor and target: the readable phrase
                                 answers "what happened", the exact dotted action is what
                                 someone greps for, quotes in a ticket, or types into the
                                 filter — and the environment console only ever showed
                                 that second one. --}}
                            <td class="whitespace-nowrap">
                                <p class="font-medium">{{ str_replace(['.', '_'], [' · ', ' '], $entry->action) }}</p>
                                <p class="text-xs mono" style="color:var(--faint)">{{ $entry->action }}</p>
                            </td>
                            {{-- Name first, id underneath. Someone reading this page is
                                 answering "who did what to whom" — the ULID is the
                                 evidence, the name is the answer. The full id stays in
                                 the title attribute so it is still copyable. --}}
                            <td>
                                @php $actorName = $names[$entry->actor_id] ?? null; @endphp
                                @if ($actorName !== null)
                                    <p class="text-sm truncate" title="{{ $entry->actor_id }}">{{ $actorName }}</p>
                                    <p class="text-xs mono truncate" style="color:var(--faint)">{{ ucfirst($entry->actor_type->value) }}</p>
                                @else
                                    <span class="badge">{{ ucfirst($entry->actor_type->value) }}</span>
                                    @if ($entry->actor_id)
                                        <span class="mono text-xs ml-1" style="color:var(--faint)" title="{{ $entry->actor_id }}">{{ Str::limit($entry->actor_id, 10, '…') }}</span>
                                    @endif
                                @endif
                            </td>
                            <td>
                                @php $targetName = $names[$entry->target_id] ?? null; @endphp
                                @if ($entry->target_type === null)
                                    <span style="color:var(--faint)">—</span>
                                @elseif ($targetName !== null)
                                    <p class="text-sm truncate" title="{{ $entry->target_id }}">{{ $targetName }}</p>
                                    <p class="text-xs truncate" style="color:var(--faint)">{{ str_replace('_', ' ', $entry->target_type) }}</p>
                                @else
                                    <span class="text-sm" style="color:var(--muted)">{{ str_replace('_', ' ', $entry->target_type) }}</span>
                                    <span class="mono text-xs ml-1" style="color:var(--faint)" title="{{ $entry->target_id }}">{{ Str::limit($entry->target_id ?? '', 10, '…') }}</span>
                                @endif

                                {{-- THE CONTEXT THE WRITER RECORDED. It was stored and never
                                     shown here — an id tells you WHICH environment was
                                     created, and "Staging" tells you which one that was at
                                     the time, which is the whole reason somebody wrote it
                                     down. Scalars only: a nested payload belongs in an
                                     export, not in a table cell, and flattening one here
                                     would produce a row nobody can read. --}}
                                @php
                                    $facts = collect($entry->context)
                                        ->filter(fn ($v): bool => is_scalar($v))
                                        ->take(3);
                                @endphp
                                @if ($facts->isNotEmpty())
                                    <p class="text-xs mt-0.5 truncate" style="color:var(--faint)"
                                       title="{{ $facts->map(fn ($v, $k): string => $k.': '.$v)->implode(', ') }}">
                                        {{ $facts->map(fn ($v, $k): string => $k.': '.$v)->implode(' · ') }}
                                    </p>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <time class="text-xs" style="color:var(--muted)" title="{{ $entry->recorded_at?->toDayDateTimeString() }}">{{ $entry->recorded_at?->diffForHumans() }}</time>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                {{-- The environment console's search empty state, kept:
                                     "nothing recorded yet" under a filter that matched
                                     nothing is a different fact, and the wrong one. --}}
                                @if (trim($search) !== '' || trim($actionFilter) !== '')
                                    <x-empty-state icon="search" title="No matching entries"
                                                   body="No entry on this page matches that action or target. Try a broader term, or clear the filter." />
                                @else
                                    <x-empty-state icon="audit" title="Nothing recorded yet"
                                                   :help="\App\Platform\Help\HelpTopic::ActivityLog"
                                                   body="Every administrative change — members, roles, connections, apps — lands here as it happens, with who did it and when. Nothing to configure; it records itself." />
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 mt-4">
        <p class="flex items-center gap-1.5 text-xs min-w-0" style="color:var(--faint)"><x-icon name="shield" class="w-3.5 h-3.5 shrink-0" /> Entries are append-only and hash-chained — any tampering breaks the chain.</p>
        <div class="max-w-full overflow-x-auto">{{ $entries->links() }}</div>
    </div>
</div>
