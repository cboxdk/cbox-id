<?php

declare(strict_types=1);

use App\Platform\AuditNames;
use App\Platform\CurrentUser;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app', ['title' => 'Activity log'])] class extends Component
{
    use WithPagination;

    public string $actionFilter = '';

    public function boot(): void
    {
        // The audit log exposes every actor, target and action in the org — a
        // sensitive, admin-only view. Members must not read it. Enforced in boot()
        // (not mount) so it re-runs on every Livewire action — pagination, filter —
        // not just the initial render.
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    public function updatingActionFilter(): void
    {
        $this->resetPage();
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $orgId = app(CurrentUser::class)->organizationId() ?? '';

        $query = AuditEntry::query()
            ->where('organization_id', $orgId)
            ->orderByDesc('sequence');

        $filter = trim($this->actionFilter);
        if ($filter !== '') {
            $query->where('action', 'like', '%'.$filter.'%');
        }

        $entries = $query->paginate(25);

        return [
            'entries' => $entries,
            // Resolved once per page, in three queries — never per row.
            'names' => app(AuditNames::class)->for($entries->getCollection()),
        ];
    }
}; ?>

<div>
    <x-page-header title="Activity log" class="flex-wrap" :help="\App\Platform\Help\HelpTopic::ActivityLog"
                   subtitle="Every change made in this organization: who did what, to what, and when. Hash-chained, so a removed entry shows up.">
        <x-slot:actions>
            <div class="relative w-full sm:w-auto">
                <input wire:model.live.debounce.300ms="actionFilter" type="text" class="input w-full sm:min-w-[16rem]" placeholder="Filter by action…" aria-label="Filter by action">
            </div>
        </x-slot:actions>
    </x-page-header>

    <div class="card overflow-hidden">
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
                        <tr>
                            <td class="mono text-xs hidden sm:table-cell" style="color:var(--faint)">{{ $entry->sequence }}</td>
                            <td class="font-medium whitespace-nowrap">{{ str_replace(['.', '_'], [' · ', ' '], $entry->action) }}</td>
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
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <time class="text-xs" style="color:var(--muted)" title="{{ $entry->recorded_at?->toDayDateTimeString() }}">{{ $entry->recorded_at?->diffForHumans() }}</time>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-empty-state icon="audit" title="Nothing recorded yet"
                                               :help="\App\Platform\Help\HelpTopic::ActivityLog"
                                               body="Every administrative change — members, roles, connections, apps — lands here as it happens, with who did it and when. Nothing to configure; it records itself." />
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
