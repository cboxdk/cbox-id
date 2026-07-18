<?php

declare(strict_types=1);

use Cbox\Id\ExternalActions\Enums\HookPoint;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

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
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $organizations = Organization::query()->orderBy('name')->get();

        $query = ExternalActionEndpoint::query()->orderByDesc('id')->limit(100);

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('url', 'like', "%{$term}%");
        }

        return [
            'orgNames' => $organizations->pluck('name', 'id'),
            'rows' => $query->get(),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Event hooks</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">External endpoints the platform calls synchronously at a hook point to enrich or veto an operation.</p>
        </div>
        <a href="{{ route('environment.hooks.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New hook</a>
    </div>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by URL">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($rows as $endpoint)
            <a href="{{ route('environment.hooks.show', $endpoint->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-xs rounded-full px-2 py-0.5 mono" style="background:var(--surface-2);color:var(--muted)">{{ $endpoint->hook_point->value }}</span>
                        <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $endpoint->organization_id !== null ? ($orgNames[$endpoint->organization_id] ?? $endpoint->organization_id) : 'All organizations' }}</span>
                    </div>
                    <p class="mt-1 text-xs truncate mono" style="color:var(--faint)">{{ $endpoint->url }}</p>
                </div>
                @if ($endpoint->status === \Cbox\Id\ExternalActions\Enums\ActionEndpointStatus::Active)
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Active</span>
                @else
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--warning-soft);color:var(--warning)">Paused</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No event hook endpoints yet. Register one to have the platform call your external logic at a hook point.</p>
        @endforelse
    </div>
</div>
