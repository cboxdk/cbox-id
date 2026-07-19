<?php

declare(strict_types=1);

use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Directories (SCIM) — list. Every directory connection
 * in this environment (host-resolved env scope via the Directory model's
 * BelongsToEnvironment). Each row deep-links to its own detail page where the SCIM
 * endpoint, bearer-token rotation, status and group→role mappings live. Creation is a
 * dedicated page — the list only lists, no inline forms.
 *
 * Access is gated by the env-admin session (route middleware), so the account member
 * has full CRUD here; there is no per-org entitlement lock at the control-plane level.
 */
new #[Layout('components.layouts.environment', ['title' => 'Directories'])] class extends Component
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
        $query = Directory::query()->orderByDesc('created_at');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            'directories' => $query->paginate(25),
            'orgNames' => Organization::query()->pluck('name', 'id'),
            'scimBaseUrl' => url('/scim/v2'),
        ];
    }
}; ?>

<div>
    <x-page-header title="Directories" subtitle="Provision and de-provision users automatically over SCIM 2.0. Point an identity provider at the endpoint below and authenticate with a directory's bearer token.">
        <x-slot:actions>
            <a href="{{ route('environment.directories.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New directory</a>
        </x-slot:actions>
    </x-page-header>

    {{-- The SCIM base URL the customer's IdP posts to; each directory authenticates it with its own token. --}}
    <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center gap-2 text-sm font-medium"><x-icon name="directory" class="w-4 h-4" /> SCIM endpoint</div>
        <p class="mt-1 text-xs" style="color:var(--faint)">Base URL for Okta, Microsoft Entra and other SCIM 2.0 clients.</p>
        <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $scimBaseUrl }}</p>
    </div>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($directories as $directory)
            <a href="{{ route('environment.directories.show', $directory->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $directory->name }}</span>
                    <p class="text-xs truncate" style="color:var(--faint)">{{ $directory->provider->label() }} · {{ $orgNames[$directory->organization_id] ?? $directory->organization_id }}</p>
                </div>
                <span class="badge {{ $directory->status->value === 'active' ? 'badge-success' : 'badge-warn' }}">{{ ucfirst($directory->status->value) }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="directory" class="w-5 h-5" /></div>
                    <h3>No matches for "{{ trim($search) }}"</h3>
                    <p>No directories match that name. Try a different search term.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="directory" class="w-5 h-5" /></div>
                    <h3>No directories connected yet</h3>
                    <p>Connect a SCIM directory to provision and de-provision users automatically.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $directories->links() }}</div>
</div>
