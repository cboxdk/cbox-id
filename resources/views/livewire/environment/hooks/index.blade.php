<?php

declare(strict_types=1);

use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Event hooks (list). The external inline-hook registry —
 * every customer HTTPS endpoint this environment owns that the platform calls
 * synchronously at a {@see HookPoint} to enrich or veto
 * an operation. Endpoints are environment-owned (BelongsToEnvironment), so the query
 * only ever sees this plane's endpoints. Each row deep-links to its own detail page
 * where the full lifecycle — pause/activate, one-time secret, delete — lives.
 * Registration is a dedicated page; the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Event hooks'])] class extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $organizations = Organization::query()->orderBy('name')->get();

        $query = ExternalActionEndpoint::query()->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('url', 'like', "%{$term}%");
        }

        return [
            'orgNames' => $organizations->pluck('name', 'id'),
            'rows' => $query->paginate(25),
        ];
    }
}; ?>

<div>
    <x-page-header title="Event hooks" subtitle="External endpoints the platform calls synchronously at a hook point to enrich or veto an operation.">
        <x-slot:actions>
            <a href="{{ route('environment.hooks.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New hook</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by URL">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($rows as $endpoint)
            <a href="{{ route('environment.hooks.show', $endpoint->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="badge mono">{{ $endpoint->hook_point->value }}</span>
                        <span class="badge">{{ $endpoint->organization_id !== null ? ($orgNames[$endpoint->organization_id] ?? $endpoint->organization_id) : 'All organizations' }}</span>
                    </div>
                    <p class="mt-1 text-xs truncate mono" style="color:var(--faint)">{{ $endpoint->url }}</p>
                </div>
                @if ($endpoint->status === \Cbox\Id\ExternalActions\Enums\ActionEndpointStatus::Active)
                    <span class="badge badge-success">Active</span>
                @else
                    <span class="badge badge-warn">Paused</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div>
                    <h3>No matching hooks</h3>
                    <p>No endpoint URL matches "{{ $search }}". Try a different search.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div>
                    <h3>No event hook endpoints yet</h3>
                    <p>Register one to have the platform call your external logic at a hook point.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
</div>
