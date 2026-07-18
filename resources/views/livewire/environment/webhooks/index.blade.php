<?php

declare(strict_types=1);

use Cbox\Id\Webhooks\Models\WebhookEndpoint;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Webhooks (list). Every subscriber endpoint this
 * environment owns (WebhookEndpoint is BelongsToEnvironment, so the query only ever
 * sees this plane's endpoints). Each row deep-links to its own detail page where the
 * full lifecycle — subscription, pause/resume, secret rotation, deliveries, delete —
 * lives. Creation is a dedicated page; the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Webhooks'])] class extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'endpoints' => WebhookEndpoint::query()->orderByDesc('id')->limit(100)->get(),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Webhooks</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Endpoints that receive signed event notifications for this environment.</p>
        </div>
        <a href="{{ route('environment.webhooks.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New webhook</a>
    </div>

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($endpoints as $endpoint)
            <a href="{{ route('environment.webhooks.show', $endpoint->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="block font-medium truncate mono">{{ $endpoint->url }}</span>
                    <p class="text-xs truncate" style="color:var(--faint)">{{ count($endpoint->event_types) }} {{ count($endpoint->event_types) === 1 ? 'event' : 'events' }} subscribed</p>
                </div>
                @if ($endpoint->status === \Cbox\Id\Webhooks\Enums\EndpointStatus::Active)
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Active</span>
                @else
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--warning-soft);color:var(--warning)">Paused</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No webhook endpoints yet. Add one to start receiving signed event notifications.</p>
        @endforelse
    </div>
</div>
