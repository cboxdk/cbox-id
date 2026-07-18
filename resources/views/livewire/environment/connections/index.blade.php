<?php

declare(strict_types=1);

use Cbox\Id\Federation\Models\Connection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Environment control plane › Single sign-on (list). Every SSO connection in the
 * environment — across all of its organizations — resolved through the Connection
 * model's BelongsToEnvironment scope, so a connection from another plane never leaks
 * in. Each row deep-links to the connection's own detail page; creation is its own
 * page. No inline forms — the list only lists.
 */
new #[Layout('components.layouts.environment', ['title' => 'Single sign-on'])] class extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = Connection::query()->orderByDesc('created_at')->limit(100);

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return ['connections' => $query->get()];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Single sign-on</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Federated SAML and OIDC connections for this environment's organizations.</p>
        </div>
        <a href="{{ route('environment.connections.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New connection</a>
    </div>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($connections as $c)
            <a href="{{ route('environment.connections.show', $c->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $c->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $c->id }}</p>
                </div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">{{ strtoupper($c->type->value) }}</span>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $c->isActive() ? 'Active' : ucfirst($c->status->value) }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No connections yet.</p>
        @endforelse
    </div>
</div>
