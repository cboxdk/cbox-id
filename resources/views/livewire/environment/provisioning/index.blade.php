<?php

declare(strict_types=1);

use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Outbound sync (list). The registry of downstream SCIM
 * targets this environment provisions users OUT to. Each row deep-links to its own
 * routable detail page where the full lifecycle lives; registration is a dedicated
 * page. No inline forms — the list only lists.
 *
 * Connections are environment-owned (BelongsToEnvironment), so every read is fenced
 * to this environment by the hard scope — an id from another plane never matches.
 */
new #[Layout('components.layouts.environment', ['title' => 'Outbound sync'])] class extends Component
{
    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'connections' => ProvisioningConnection::query()->orderByDesc('id')->get(),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Outbound sync</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Push users out to your downstream SaaS apps over their SCIM 2.0 endpoints. Changes are provisioned to each connected app.</p>
        </div>
        <a href="{{ route('environment.provisioning.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New connection</a>
    </div>

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($connections as $connection)
            <a href="{{ route('environment.provisioning.show', $connection->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $connection->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $connection->base_url }}</p>
                </div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $connection->organization_id === null ? 'Environment-wide' : 'Org-scoped' }}</span>
                @if ($connection->status === \Cbox\Id\Provisioning\Enums\ConnectionStatus::Active)
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Active</span>
                @else
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Paused</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No provisioning connections yet. Register one to start pushing user changes out to a downstream app over its SCIM endpoint.</p>
        @endforelse
    </div>
</div>
