<?php

declare(strict_types=1);

use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Support\IdpDescriptor;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

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
    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'providers' => app(ServiceProviders::class)->all(),
            'idpEntityId' => IdpDescriptor::entityId(),
            'idpMetadataUrl' => IdpDescriptor::metadataUrl(),
            'idpSsoUrl' => IdpDescriptor::ssoUrl(),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Login methods</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Register the applications that use this environment as their SAML identity provider.</p>
        </div>
        <a href="{{ route('environment.sso-providers.create') }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> Add method</a>
    </div>

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

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($providers as $sp)
            <a href="{{ route('environment.sso-providers.show', $sp->id) }}"
               class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate mono">{{ $sp->entity_id }}</span>
                    <p class="text-xs truncate mono" style="color:var(--faint)">{{ $sp->id }}</p>
                </div>
                @if ($sp->want_authn_requests_signed)
                    <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Signed requests</span>
                @endif
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $sp->isActive() ? 'Active' : ucfirst($sp->status->value) }}</span>
                <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
            </a>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No login methods yet. Add one to let an application sign users in through this environment.</p>
        @endforelse
    </div>
</div>
