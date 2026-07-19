<?php

declare(strict_types=1);

use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

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
        $query = ProvisioningConnection::query()->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            'connections' => $query->paginate(25),
        ];
    }
}; ?>

<div>
    <x-page-header title="Outbound sync" subtitle="Push users out to your downstream SaaS apps over their SCIM 2.0 endpoints. Changes are provisioned to each connected app.">
        <x-slot:actions>
            <a href="{{ route('environment.provisioning.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New connection</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($connections as $connection)
            <a href="{{ route('environment.provisioning.show', $connection->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $connection->name }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $connection->base_url }}</p>
                </div>
                <span class="badge">{{ $connection->organization_id === null ? 'Environment-wide' : 'Org-scoped' }}</span>
                @if ($connection->status === \Cbox\Id\Provisioning\Enums\ConnectionStatus::Active)
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
                    <h3>No matches for "{{ trim($search) }}"</h3>
                    <p>No connections match that name. Try a different search term.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="layers" class="w-5 h-5" /></div>
                    <h3>No provisioning connections yet</h3>
                    <p>Register one to start pushing user changes out to a downstream app over its SCIM endpoint.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $connections->links() }}</div>
</div>
