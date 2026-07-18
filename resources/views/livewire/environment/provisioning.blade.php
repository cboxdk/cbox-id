<?php

declare(strict_types=1);

use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Outbound sync — the registry of downstream SCIM
 * targets this environment provisions users OUT to.
 *
 * Provisioning connections are environment-owned (BelongsToEnvironment), so every
 * read and resolve is fenced to this environment by the hard scope — an id from
 * another plane never matches, closing cross-tenant id tampering. Access is gated
 * by the env-admin session (route middleware), so the account member has full CRUD
 * here; there is no per-org entitlement lock at the control-plane level.
 *
 * A connection is per-org (`organization_id`) or environment-wide (null), so the
 * create form carries an organization picker with an "All organizations" option.
 */
new #[Layout('components.layouts.environment', ['title' => 'Outbound sync'])] class extends Component
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

    public bool $creating = false;

    /**
     * Register a downstream SCIM target. `register()` accepts a nullable org id —
     * an empty picker value means an environment-wide connection, so it is coerced
     * to null rather than a blank string.
     */
    public function register(ProvisioningConnections $connections): void
    {
        $this->validate();

        $connections->register(
            $this->organizationId !== '' ? $this->organizationId : null,
            $this->name,
            $this->baseUrl,
            AuthScheme::from($this->scheme),
            $this->secret,
        );

        $this->reset('name', 'baseUrl', 'organizationId', 'scheme', 'secret', 'creating');
        session()->flash('status', 'Provisioning connection registered.');
    }

    /**
     * Pause a connection THIS environment owns, or refuse. The BelongsToEnvironment
     * scope restricts the lookup to this environment, so an id from another plane
     * resolves to null and is a 404 — never a cross-tenant mutation (deny-by-default).
     */
    public function pause(string $connectionId, ProvisioningConnections $connections): void
    {
        $connection = ProvisioningConnection::query()->whereKey($connectionId)->first();

        abort_if($connection === null, 404);

        $connections->pause($connection->id);
        session()->flash('status', 'Connection paused.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'connections' => ProvisioningConnection::query()->orderByDesc('id')->get(),
            'organizations' => Organization::query()->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Outbound sync</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Push users out to your downstream SaaS apps over their SCIM 2.0 endpoints. Changes are provisioned to each connected app.</p>
        </div>
        <button wire:click="$toggle('creating')" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> Register connection</button>
    </div>

    @if ($creating)
        <form wire:submit="register" class="mt-6 rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
            <div>
                <label class="label" for="name">Name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="Downstream app" autofocus>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
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
                @error('organizationId') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="baseUrl">SCIM base URL</label>
                <input wire:model="baseUrl" id="baseUrl" type="url" class="input mono" placeholder="https://app.example.com/scim/v2">
                @error('baseUrl') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="scheme">Auth scheme</label>
                <select wire:model="scheme" id="scheme" class="select">
                    <option value="bearer">Bearer token</option>
                    <option value="oauth2_client_credentials">OAuth2 client credentials</option>
                </select>
                @error('scheme') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="secret">Secret</label>
                <input wire:model="secret" id="secret" type="password" class="input" placeholder="Bearer token or OAuth client secret" autocomplete="new-password">
                <p class="mt-1 text-xs" style="color:var(--faint)">Sealed at rest and never shown again. Supply the downstream app's bearer token or OAuth client secret.</p>
                @error('secret') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Register</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($connections as $connection)
            <div class="rounded-xl border p-5" style="border-color:var(--border)">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-semibold truncate">{{ $connection->name }}</p>
                            @if ($connection->status === \Cbox\Id\Provisioning\Enums\ConnectionStatus::Active)
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Active</span>
                            @else
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Paused</span>
                            @endif
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $connection->auth_scheme->value }}</span>
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $connection->organization_id === null ? 'Environment-wide' : 'Org-scoped' }}</span>
                        </div>
                        <p class="mt-1 text-xs mono truncate" style="color:var(--faint)">{{ $connection->id }}</p>
                        @if ($connection->last_error)
                            <p class="mt-1 text-xs" style="color:var(--destructive)">{{ $connection->last_error }}</p>
                        @endif
                    </div>
                    @if ($connection->status === \Cbox\Id\Provisioning\Enums\ConnectionStatus::Active)
                        <button wire:click="pause('{{ $connection->id }}')"
                                wire:confirm="Pause this connection? It will stop provisioning changes to the downstream app."
                                class="btn btn-ghost btn-sm shrink-0">Pause</button>
                    @endif
                </div>

                <div class="mt-4">
                    <p class="label">SCIM base URL</p>
                    <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $connection->base_url }}</p>
                </div>
            </div>
        @empty
            <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">No provisioning connections yet. Register one to start pushing user changes out to a downstream app over its SCIM endpoint.</p>
        @endforelse
    </div>
</div>
