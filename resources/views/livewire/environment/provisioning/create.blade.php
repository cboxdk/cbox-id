<?php

declare(strict_types=1);

use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Outbound sync › New. A dedicated, deep-linkable create
 * page for registering a downstream SCIM target. A connection is per-org
 * (`organization_id`) or environment-wide (null), so the form carries an organization
 * picker with an "All organizations" option. The secret is sealed at rest and never
 * shown again. On success we route straight to the new connection's detail page.
 */
new #[Layout('components.layouts.environment', ['title' => 'New outbound connection'])] class extends Component
{
    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('required|url|max:500')]
    public string $baseUrl = '';

    /** Empty ⇒ an environment-wide connection covering every subject in the environment. */
    public string $organizationId = '';

    #[Validate('required|string|in:bearer,oauth2_client_credentials')]
    public string $scheme = 'bearer';

    #[Validate('required|string|max:4096')]
    public string $secret = '';

    /**
     * Register a downstream SCIM target. `register()` accepts a nullable org id —
     * an empty picker value means an environment-wide connection, so it is coerced
     * to null rather than a blank string.
     */
    public function create(ProvisioningConnections $connections): mixed
    {
        $this->validate();

        $model = $connections->register(
            $this->organizationId !== '' ? $this->organizationId : null,
            $this->name,
            $this->baseUrl,
            AuthScheme::from($this->scheme),
            $this->secret,
        )->connection;

        session()->flash('status', 'Provisioning connection registered.');

        return $this->redirectRoute('environment.provisioning.show', ['sync' => $model->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'organizations' => Organization::query()->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <a href="{{ route('environment.provisioning') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Outbound sync</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New outbound connection</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Register a downstream app to provision users out to over its SCIM 2.0 endpoint.</p>

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="name">Name</label>
            <input wire:model="name" id="name" type="text" class="input" placeholder="Downstream app" autofocus>
            @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="organizationId">Organization</label>
            <select wire:model="organizationId" id="organizationId" class="select">
                <option value="">All organizations</option>
                @foreach ($organizations as $org)
                    <option value="{{ $org->id }}">{{ $org->name }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs" style="color:var(--faint)">Leave as "All organizations" for an environment-wide connection.</p>
            @error('organizationId') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="baseUrl">SCIM base URL</label>
            <input wire:model="baseUrl" id="baseUrl" type="url" class="input mono" placeholder="https://app.example.com/scim/v2">
            @error('baseUrl') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="scheme">Auth scheme</label>
            <select wire:model="scheme" id="scheme" class="select">
                <option value="bearer">Bearer token</option>
                <option value="oauth2_client_credentials">OAuth2 client credentials</option>
            </select>
            @error('scheme') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="secret">Secret</label>
            <input wire:model="secret" id="secret" type="password" class="input" placeholder="Bearer token or OAuth client secret" autocomplete="new-password">
            <p class="mt-1 text-xs" style="color:var(--faint)">Sealed at rest and never shown again. Supply the downstream app's bearer token or OAuth client secret.</p>
            @error('secret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Register connection</button>
            <a href="{{ route('environment.provisioning') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
