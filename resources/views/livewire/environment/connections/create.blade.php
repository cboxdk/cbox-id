<?php

declare(strict_types=1);

use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\SamlMetadataImportFailed;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Saml\SamlMetadataImporter;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Single sign-on › New. A dedicated, deep-linkable create
 * page carrying the full IdP config (SAML or OIDC). A connection belongs to one of the
 * environment's organizations, so the admin picks the owning tenant here. On success we
 * route straight to the new connection's detail page, where it starts life as a draft.
 */
new #[Layout('components.layouts.environment', ['title' => 'New connection'])] class extends Component
{
    #[Validate('required|string')]
    public string $organization_id = '';

    #[Validate('required|in:saml,oidc')]
    public string $type = 'saml';

    #[Validate('required|string|max:120')]
    public string $name = '';

    /** Pasted IdP metadata XML, or a metadata URL — one-shot prefill for the SAML fields. */
    public string $metadataInput = '';

    // SAML config
    public string $idp_entity_id = '';

    public string $idp_sso_url = '';

    public string $idp_x509cert = '';

    public string $sp_entity_id = '';

    public string $sp_acs_url = '';

    // OIDC config
    public string $issuer = '';

    public string $client_id = '';

    public string $client_secret = '';

    public string $signing_key = '';

    /**
     * Prefill the SAML fields from an IdP's metadata — either pasted XML or a metadata
     * URL. Parsed by the vetted framework importer; only the IdP fields are filled, and
     * the admin still reviews and submits.
     */
    public function importMetadata(SamlMetadataImporter $importer): void
    {
        $input = trim($this->metadataInput);

        if ($input === '') {
            $this->addError('metadataInput', 'Paste the IdP metadata XML, or a metadata URL.');

            return;
        }

        try {
            $metadata = str_starts_with($input, 'http://') || str_starts_with($input, 'https://')
                ? $importer->fromUrl($input)
                : $importer->fromXml($input);
        } catch (SamlMetadataImportFailed|UnsafeFederationUrl $e) {
            $this->addError('metadataInput', $e->getMessage());

            return;
        }

        $this->type = 'saml';
        $this->idp_entity_id = $metadata->entityId;
        $this->idp_sso_url = $metadata->ssoUrl;
        $this->idp_x509cert = $metadata->x509cert;
        $this->reset('metadataInput');

        session()->flash('status', 'Metadata imported — review the fields and create the connection.');
    }

    public function create(Connections $connections): mixed
    {
        $type = ConnectionType::from($this->validate()['type']);

        // The owning tenant must live in THIS environment; a foreign id never matches
        // the env-scoped organization query (deny-by-default).
        if (Organization::query()->whereKey($this->organization_id)->doesntExist()) {
            $this->addError('organization_id', 'That organization is not in this environment.');

            return null;
        }

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
                'client_secret' => 'required|string|max:500',
                'signing_key' => 'required|string',
            ]);
        }

        $connection = $connections->create($this->organization_id, $type, trim($this->name), $config);

        session()->flash('status', 'Connection created as a draft.');

        return $this->redirectRoute('environment.connections.show', ['connection' => $connection->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'organizations' => Organization::query()
                ->where('status', '!=', OrganizationStatus::Deleted->value)
                ->orderBy('name')
                ->pluck('name', 'id'),
        ];
    }
}; ?>

<div>
    <a href="{{ route('environment.connections') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Single sign-on</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New connection</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Configure a SAML or OIDC identity provider for one of this environment's organizations. It starts as a draft.</p>

    <form wire:submit="create" class="mt-6 max-w-2xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="organization_id">Organization</label>
                <select wire:model="organization_id" id="organization_id" class="select">
                    <option value="">Select organization…</option>
                    @foreach ($organizations as $orgId => $orgName)
                        <option value="{{ $orgId }}">{{ $orgName }}</option>
                    @endforeach
                </select>
                @error('organization_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="name">Connection name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="Acme Okta">
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="label" for="type">Protocol</label>
            <select wire:model.live="type" id="type" class="select">
                <option value="saml">SAML 2.0</option>
                <option value="oidc">OpenID Connect</option>
            </select>
            @error('type') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        @if ($type === 'saml')
            {{-- Onboarding shortcut: paste the IdP's metadata (or its URL) to fill the
                 three IdP fields below in one step, instead of copying by hand. --}}
            <div class="rounded-xl p-4" style="background:var(--surface-2);border:1px solid var(--border)">
                <label class="label" for="metadataInput">Import from IdP metadata <span style="color:var(--faint);font-weight:400">— optional</span></label>
                <textarea wire:model="metadataInput" id="metadataInput" rows="2" class="input mono" style="font-size:0.78rem"
                          placeholder="Paste the IdP metadata XML, or a metadata URL (https://idp.example.com/metadata)"></textarea>
                @error('metadataInput') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                <button type="button" wire:click="importMetadata" class="btn btn-ghost btn-sm mt-2"
                        wire:loading.attr="disabled" wire:target="importMetadata">Prefill from metadata</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="idp_entity_id">IdP entity ID</label>
                    <input wire:model="idp_entity_id" id="idp_entity_id" type="text" class="input mono" placeholder="https://idp.example.com/metadata">
                    @error('idp_entity_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="idp_sso_url">IdP SSO URL</label>
                    <input wire:model="idp_sso_url" id="idp_sso_url" type="url" class="input mono" placeholder="https://idp.example.com/sso">
                    @error('idp_sso_url') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="sp_entity_id">SP entity ID</label>
                    <input wire:model="sp_entity_id" id="sp_entity_id" type="text" class="input mono" placeholder="https://cbox-id/sp">
                    @error('sp_entity_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="sp_acs_url">SP ACS URL</label>
                    <input wire:model="sp_acs_url" id="sp_acs_url" type="url" class="input mono" placeholder="https://cbox-id/sso/saml/…/acs">
                    @error('sp_acs_url') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="idp_x509cert">IdP X.509 certificate</label>
                <textarea wire:model="idp_x509cert" id="idp_x509cert" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                @error('idp_x509cert') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="issuer">Issuer</label>
                    <input wire:model="issuer" id="issuer" type="url" class="input mono" placeholder="https://idp.example.com">
                    @error('issuer') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="client_id">Client ID</label>
                    <input wire:model="client_id" id="client_id" type="text" class="input mono" placeholder="cbox-id-app">
                    @error('client_id') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="client_secret">Client secret</label>
                    <input wire:model="client_secret" id="client_secret" type="password" class="input mono" placeholder="••••••••">
                    @error('client_secret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="signing_key">Signing key</label>
                <textarea wire:model="signing_key" id="signing_key" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN PUBLIC KEY-----"></textarea>
                @error('signing_key') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @endif

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create connection</button>
            <a href="{{ route('environment.connections') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
