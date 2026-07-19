<?php

declare(strict_types=1);

use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Cbox\Id\SamlIdp\Support\IdpDescriptor;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Environment control plane › Login methods (list). The connector library: the
 * downstream SAML service providers that federate to this environment's IdP. Each
 * row deep-links to its own routable detail page; creation is a dedicated page — the
 * list only lists.
 *
 * Service providers are environment-owned (BelongsToEnvironment), so the registry
 * only ever resolves an SP within this environment — an id from another plane never
 * matches, closing cross-tenant id tampering. Access is gated by the env-admin
 * session (route middleware).
 */
new #[Layout('components.layouts.environment', ['title' => 'Login methods'])] class extends Component
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
        $query = ServiceProvider::query()->orderBy('entity_id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('entity_id', 'like', "%{$term}%");
        }

        return [
            'providers' => $query->paginate(25),
            'idpEntityId' => IdpDescriptor::entityId(),
            'idpMetadataUrl' => IdpDescriptor::metadataUrl(),
            'idpSsoUrl' => IdpDescriptor::ssoUrl(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Login methods" subtitle="Register the applications that use this environment as their SAML identity provider.">
        <x-slot:actions>
            <a href="{{ route('environment.sso-providers.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> Add method</a>
        </x-slot:actions>
    </x-page-header>

    {{-- The IdP coordinates the admin hands to the service provider being registered. --}}
    <div class="mt-6 rounded-xl border p-5 space-y-3" style="border-color:var(--border)">
        <p class="text-sm font-medium">Your identity provider</p>
        <p class="text-xs" style="color:var(--faint)">Give these to the service provider so it can trust assertions from this environment.</p>
        <div>
            <p class="label">IdP entity ID</p>
            <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $idpEntityId }}</p>
        </div>
        <div>
            <p class="label">Metadata URL</p>
            <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $idpMetadataUrl }}</p>
        </div>
        <div>
            <p class="label">Sign-on URL</p>
            <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $idpSsoUrl }}</p>
        </div>
    </div>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by entity ID">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($providers as $sp)
            <a href="{{ route('environment.sso-providers.show', $sp->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate mono">{{ $sp->entity_id }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $sp->id }}</p>
                </div>
                @if ($sp->want_authn_requests_signed)
                    <span class="cbx-pill cbx-pill--info">Signed requests</span>
                @endif
                <span class="badge {{ $sp->isActive() ? 'badge-success' : '' }}">{{ $sp->isActive() ? 'Active' : ucfirst($sp->status->value) }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            @if (trim($search) !== '')
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                    <h3>No matches for "{{ trim($search) }}"</h3>
                    <p>No login methods match that entity ID. Try a different search term.</p>
                </div>
            @else
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                    <h3>No login methods yet</h3>
                    <p>Add one to let an application sign users in through this environment.</p>
                </div>
            @endif
        @endforelse
    </div>

    <div class="mt-4">{{ $providers->links() }}</div>
</div>
