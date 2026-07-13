<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'SSO connections'])] class extends Component
{
    public bool $creating = false;

    #[Validate('required|in:saml,oidc')]
    public string $type = 'saml';

    #[Validate('required|string|max:120')]
    public string $name = '';

    // SAML config
    public string $idp_entity_id = '';

    public string $idp_sso_url = '';

    public string $idp_x509cert = '';

    public string $sp_entity_id = '';

    public string $sp_acs_url = '';

    // OIDC config
    public string $issuer = '';

    public string $client_id = '';

    public string $signing_key = '';

    public function create(Connections $connections): void
    {
        $this->authorizeAdmin();

        $type = ConnectionType::from($this->validate()['type']);

        if ($type === ConnectionType::Saml) {
            $config = $this->validate([
                'idp_entity_id' => 'required|string|max:500',
                'idp_sso_url' => 'required|url|max:500',
                'idp_x509cert' => 'required|string',
                'sp_entity_id' => 'required|string|max:500',
                'sp_acs_url' => 'required|url|max:500',
            ]);
        } else {
            $config = $this->validate([
                'issuer' => 'required|url|max:500',
                'client_id' => 'required|string|max:500',
                'signing_key' => 'required|string',
            ]);
        }

        $connections->create($this->orgId(), $type, $this->name, $config);

        $this->reset(
            'creating', 'name', 'idp_entity_id', 'idp_sso_url', 'idp_x509cert',
            'sp_entity_id', 'sp_acs_url', 'issuer', 'client_id', 'signing_key',
        );
        $this->type = 'saml';
        session()->flash('status', 'Connection created as a draft.');
    }

    public function activate(string $id, Connections $connections): void
    {
        $this->authorizeAdmin();

        $connections->activate($this->orgId(), $id);
        session()->flash('status', 'Connection activated.');
    }

    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            'connections' => Connection::query()
                ->where('organization_id', $this->orgId())
                ->orderByDesc('created_at')
                ->get(),
        ];
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    public function mount(): void
    {
        // Read gate: these pages expose org-wide config (client secrets shown
        // once, SSO connection settings, directory tokens, audit) — admins only.
        $this->authorizeAdmin();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }
}; ?>

<div>
    <x-page-header title="SSO connections" subtitle="Federate sign-in with your enterprise identity provider.">
        <x-slot:actions>
            @if ($me->isAdmin())
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New connection</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card p-3 mb-5 text-sm flex items-center gap-2" style="border-color:color-mix(in srgb, var(--success) 30%, transparent);background:var(--success-soft);color:var(--success)">
            <x-icon name="check" class="w-4 h-4" /> {{ session('status') }}
        </div>
    @endif

    @if ($creating && $me->isAdmin())
        <form wire:submit="create" class="card p-5 mb-5 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="name">Connection name</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="Acme Okta" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="type">Protocol</label>
                    <select wire:model.live="type" id="type" class="input">
                        <option value="saml">SAML 2.0</option>
                        <option value="oidc">OpenID Connect</option>
                    </select>
                    @error('type') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($type === 'saml')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="idp_entity_id">IdP entity ID</label>
                        <input wire:model="idp_entity_id" id="idp_entity_id" type="text" class="input mono" placeholder="https://idp.example.com/metadata">
                        @error('idp_entity_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="idp_sso_url">IdP SSO URL</label>
                        <input wire:model="idp_sso_url" id="idp_sso_url" type="url" class="input mono" placeholder="https://idp.example.com/sso">
                        @error('idp_sso_url') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="sp_entity_id">SP entity ID</label>
                        <input wire:model="sp_entity_id" id="sp_entity_id" type="text" class="input mono" placeholder="https://cbox-id/sp">
                        @error('sp_entity_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="sp_acs_url">SP ACS URL</label>
                        <input wire:model="sp_acs_url" id="sp_acs_url" type="url" class="input mono" placeholder="https://cbox-id/sso/saml/…/acs">
                        @error('sp_acs_url') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label" for="idp_x509cert">IdP X.509 certificate</label>
                    <textarea wire:model="idp_x509cert" id="idp_x509cert" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                    @error('idp_x509cert') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="issuer">Issuer</label>
                        <input wire:model="issuer" id="issuer" type="url" class="input mono" placeholder="https://idp.example.com">
                        @error('issuer') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="client_id">Client ID</label>
                        <input wire:model="client_id" id="client_id" type="text" class="input mono" placeholder="cbox-id-app">
                        @error('client_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label" for="signing_key">Signing key</label>
                    <textarea wire:model="signing_key" id="signing_key" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN PUBLIC KEY-----"></textarea>
                    @error('signing_key') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create connection</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="space-y-4">
        @forelse ($connections as $c)
            <div class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <p class="font-semibold truncate">{{ $c->name }}</p>
                            <span class="badge">{{ strtoupper($c->type->value) }}</span>
                            @if ($c->isActive())
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge">{{ ucfirst($c->status->value) }}</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs mono truncate" style="color:var(--faint)">{{ $c->id }}</p>
                    </div>
                    @if ($me->isAdmin() && ! $c->isActive())
                        <button wire:click="activate('{{ $c->id }}')" class="btn btn-primary" style="padding:0.35rem 0.7rem;font-size:0.8rem"><x-icon name="check" class="w-4 h-4" /> Activate</button>
                    @endif
                </div>

                @if ($c->type === \Cbox\Id\Federation\Enums\ConnectionType::Saml)
                    <div class="mt-4">
                        <p class="label">ACS URL</p>
                        <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ url("/sso/saml/{$c->id}/acs") }}</p>
                    </div>
                @endif
            </div>
        @empty
            <div class="card p-10 text-center">
                <div class="mx-auto grid place-items-center rounded-full" style="width:2.5rem;height:2.5rem;background:var(--accent-soft);color:var(--accent)"><x-icon name="connections" class="w-5 h-5" /></div>
                <p class="mt-3 font-medium">No SSO connections yet</p>
                <p class="mt-1 text-sm" style="color:var(--faint)">Connect an identity provider to let your team sign in with SAML or OIDC.</p>
            </div>
        @endforelse
    </div>
</div>
