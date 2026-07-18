<?php

declare(strict_types=1);

use Cbox\Id\TokenVault\Models\VaultSecret;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Environment control plane › Stored tokens (list). The downstream credential vault:
 * the API keys and tokens this environment's AI agents present to third parties
 * (OpenAI, GitHub, …). Each row deep-links to its own detail page where rotation,
 * grants and revocation live; creation is a dedicated page. No inline forms — the
 * list only lists, and a sealed value is NEVER echoed here.
 *
 * Secrets are environment-owned (BelongsToEnvironment), so the query resolves only
 * within this environment — an id from another plane never appears (deny-by-default).
 */
new #[Layout('components.layouts.environment', ['title' => 'Stored tokens'])] class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = VaultSecret::query()->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            'secrets' => $query->get(),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Stored tokens</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Downstream API keys your AI agents present to providers. Each value is sealed at rest and brokered only to explicitly granted clients — it is never shown again after you store it.</p>
        </div>
        <a href="{{ route('environment.vault.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New secret</a>
    </div>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($secrets as $s)
            <a href="{{ route('environment.vault.show', $s->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $s->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $s->provider }}</p>
                </div>
                @if ($s->isRevoked())
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--destructive-soft);color:var(--destructive)">Revoked</span>
                @elseif ($s->isExpired())
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--warning-soft);color:var(--warning)">Expired</span>
                @else
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Active</span>
                @endif
                @if ($s->owner_type === 'organization')
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Org-scoped</span>
                @endif
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No secrets yet. Store a downstream API key to broker it to this environment's agents.</p>
        @endforelse
    </div>
</div>
