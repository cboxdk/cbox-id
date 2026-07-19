<?php

declare(strict_types=1);

use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Webhooks (list). Every subscriber endpoint this
 * environment owns (WebhookEndpoint is BelongsToEnvironment, so the query only ever
 * sees this plane's endpoints). Each row deep-links to its own detail page where the
 * full lifecycle — subscription, pause/resume, secret rotation, deliveries, delete —
 * lives. Creation is a dedicated page; the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Webhooks'])] class extends Component
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
        $query = WebhookEndpoint::query()->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('url', 'like', "%{$term}%");
        }

        return [
            'endpoints' => $query->paginate(25),
        ];
    }
}; ?>

<div>
    <x-page-header title="Webhooks" subtitle="Endpoints that receive signed event notifications for this environment.">
        <x-slot:actions>
            <a href="{{ route('environment.webhooks.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New webhook</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by URL">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($endpoints as $endpoint)
            <a href="{{ route('environment.webhooks.show', $endpoint->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="block font-medium truncate mono">{{ $endpoint->url }}</span>
                    <p class="text-xs truncate" style="color:var(--faint)">{{ count($endpoint->event_types) }} {{ count($endpoint->event_types) === 1 ? 'event' : 'events' }} subscribed</p>
                </div>
                @if ($endpoint->status === \Cbox\Id\Webhooks\Enums\EndpointStatus::Active)
                    <span class="badge badge-success">Active</span>
                @else
                    <span class="badge badge-warn">Paused</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="webhooks" class="w-5 h-5" /></div>
                    <h3>No matching webhooks</h3>
                    <p>No endpoint URL matches "{{ $search }}". Try a different search.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="webhooks" class="w-5 h-5" /></div>
                    <h3>No webhook endpoints yet</h3>
                    <p>Add one to start receiving signed event notifications.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $endpoints->links() }}</div>
</div>
