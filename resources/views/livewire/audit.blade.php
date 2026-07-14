<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app', ['title' => 'Audit log'])] class extends Component
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

        return ['entries' => $query->paginate(25)];
    }
}; ?>

<div>
    <x-page-header title="Audit log" subtitle="Every change to this organization, tamper-evident and hash-chained.">
        <x-slot:actions>
            <div class="relative w-full sm:w-auto">
                <input wire:model.live.debounce.300ms="actionFilter" type="text" class="input w-full sm:min-w-[16rem]" placeholder="Filter by action…">
            </div>
        </x-slot:actions>
    </x-page-header>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:1%">Seq</th>
                        <th scope="col">Action</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Target</th>
                        <th class="text-right">When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td class="mono text-xs" style="color:var(--faint)">{{ $entry->sequence }}</td>
                            <td class="font-medium whitespace-nowrap">{{ str_replace(['.', '_'], [' · ', ' '], $entry->action) }}</td>
                            <td>
                                <span class="badge">{{ ucfirst($entry->actor_type->value) }}</span>
                                @if ($entry->actor_id)
                                    <span class="mono text-xs ml-1" style="color:var(--faint)">{{ Str::limit($entry->actor_id, 10, '…') }}</span>
                                @endif
                            </td>
                            <td>
                                @if ($entry->target_type)
                                    <span class="text-sm" style="color:var(--muted)">{{ $entry->target_type }}</span>
                                    <span class="mono text-xs ml-1" style="color:var(--faint)">{{ Str::limit($entry->target_id ?? '', 10, '…') }}</span>
                                @else
                                    <span style="color:var(--faint)">—</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <time class="text-xs" style="color:var(--muted)" title="{{ $entry->recorded_at?->toDayDateTimeString() }}">{{ $entry->recorded_at?->diffForHumans() }}</time>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center py-12" style="color:var(--faint)">No audit entries recorded yet.</td></tr>
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
