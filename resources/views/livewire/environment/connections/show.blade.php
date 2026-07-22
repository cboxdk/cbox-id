<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\OidcDiscoveryFailed;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\OidcDiscovery;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Single sign-on › detail. The full, deep-linkable
 * lifecycle for one connection: edit the IdP config, enable/disable and delete.
 *
 * Every read and mutation re-resolves the connection through the Connection model's
 * BelongsToEnvironment scope and 404s otherwise — an id from another plane never
 * matches (deny-by-default). The IdP config is sealed at rest: it is decrypted only
 * to prefill the editable fields, certificates and signing keys are never echoed back,
 * and a save re-seals through the Crypto kernel exactly as creation does.
 */
new #[Layout('components.layouts.environment', ['title' => 'SSO connection'])] class extends Component
{
    /**
     * Second layer. The route's `env.admin` middleware is the primary gate and IS
     * re-run on Livewire actions (PersistentMiddlewareTest holds that), but this
     * console previously had NO in-component authorization at all — so when that
     * middleware was missing from the persistent list, every action here answered
     * unauthenticated. boot() rather than mount(): only boot() runs on each action.
     */
    public function boot(): void
    {
        abort_if(app(EnvironmentAdminAuth::class)->current() === null, 403);
    }

    public string $connectionId = '';

    public string $editName = '';

    // SAML config
    public string $idp_entity_id = '';

    public string $idp_sso_url = '';

    /** Sealed secret — never prefilled; blank on save keeps the stored certificate. */
    public string $idp_x509cert = '';

    public string $sp_entity_id = '';

    public string $sp_acs_url = '';

    // OIDC config
    public string $issuer = '';

    public string $client_id = '';

    /** Sealed secrets — never prefilled; blank on save keeps the stored value. */
    public string $client_secret = '';

    public string $signing_key = '';

    public function mount(string $connection, Connections $connections): void
    {
        $model = Connection::query()->whereKey($connection)->first();
        abort_if($model === null, 404);

        $this->connectionId = $model->id;
        $this->editName = $model->name;
        $this->hydrateConfig($model, $connections);
    }

    private function connection(): Connection
    {
        $model = Connection::query()->whereKey($this->connectionId)->first();
        abort_if($model === null, 404);

        return $model;
    }

    /**
     * Prefill the editable IdP fields from the sealed config. Certificates and signing
     * keys are deliberately NOT prefilled — a sealed secret is never returned to the
     * browser; leaving the field blank on save preserves the stored value.
     */
    private function hydrateConfig(Connection $model, Connections $connections): void
    {
        $config = $this->safeConfig($model, $connections);

        $this->idp_entity_id = (string) ($config['idp_entity_id'] ?? '');
        $this->idp_sso_url = (string) ($config['idp_sso_url'] ?? '');
        $this->sp_entity_id = (string) ($config['sp_entity_id'] ?? '');
        $this->sp_acs_url = (string) ($config['sp_acs_url'] ?? '');
        $this->issuer = (string) ($config['issuer'] ?? '');
        $this->client_id = (string) ($config['client_id'] ?? '');
    }

    /**
     * The decrypted config, or an empty array if the ciphertext can't be opened (a
     * rotated key or tampered record). A broken seal must never fatal the edit page.
     *
     * @return array<string, mixed>
     */
    private function safeConfig(Connection $model, Connections $connections): array
    {
        try {
            return $connections->config($model);
        } catch (Throwable) {
            return [];
        }
    }

    public function saveConfig(Connections $connections, SecretBox $secretBox): void
    {
        $model = $this->connection();

        $data = $this->validate(['editName' => ['required', 'string', 'max:120']]);

        // Start from the sealed config so any secret left blank is carried through.
        $current = $this->safeConfig($model, $connections);

        if ($model->type === ConnectionType::Saml) {
            $this->validate([
                'idp_entity_id' => 'required|string|max:500',
                'idp_sso_url' => 'required|url|max:500',
                'sp_entity_id' => 'required|string|max:500',
                'sp_acs_url' => 'required|url|max:500',
            ]);

            $cert = trim($this->idp_x509cert) !== '' ? trim($this->idp_x509cert) : (string) ($current['idp_x509cert'] ?? '');
            if ($cert === '') {
                $this->addError('idp_x509cert', 'A signing certificate is required.');

                return;
            }

            $config = [
                'idp_entity_id' => trim($this->idp_entity_id),
                'idp_sso_url' => trim($this->idp_sso_url),
                'idp_x509cert' => $cert,
                'sp_entity_id' => trim($this->sp_entity_id),
                'sp_acs_url' => trim($this->sp_acs_url),
            ];
        } else {
            $this->validate([
                'issuer' => 'required|url|max:500',
                'client_id' => 'required|string|max:500',
            ]);

            $key = trim($this->signing_key) !== '' ? trim($this->signing_key) : (string) ($current['signing_key'] ?? '');
            if ($key === '') {
                $this->addError('signing_key', 'A signing key is required.');

                return;
            }

            // Secrets are write-once: a blank field keeps the sealed value.
            $secret = trim($this->client_secret) !== '' ? trim($this->client_secret) : (string) ($current['client_secret'] ?? '');
            if ($secret === '') {
                $this->addError('client_secret', 'A client secret is required.');

                return;
            }

            $config = [
                'issuer' => trim($this->issuer),
                'client_id' => trim($this->client_id),
                'client_secret' => $secret,
                'signing_key' => $key,
            ];

            try {
                $config = array_merge($config, app(OidcDiscovery::class)->fromIssuer($this->issuer)->toConfig());
            } catch (OidcDiscoveryFailed|UnsafeFederationUrl $e) {
                $this->addError('issuer', "Couldn't read the provider's OpenID configuration — check the issuer URL. ({$e->getMessage()})");

                return;
            }
        }

        $model->name = trim($data['editName']);
        $model->config_encrypted = $secretBox->seal(
            json_encode($config, JSON_THROW_ON_ERROR),
            $model->secretContext(),
        );
        $model->save();

        // Never keep a secret in component state once it has been sealed.
        $this->reset('idp_x509cert', 'signing_key', 'client_secret');

        $this->dispatch('toast', message: 'Connection updated.');
    }

    public function activate(Connections $connections): void
    {
        $model = $this->connection();
        // The service scopes the flip to the owning org, so a draft can't be activated
        // across tenants.
        $connections->activate($model->organization_id, $model->id);
        $this->dispatch('toast', message: 'Connection activated.');
    }

    public function disable(): void
    {
        // No service method disables a connection; the status flips on the env-scoped
        // model directly (mirrors how the organization console persists status changes).
        $model = $this->connection();
        $model->status = ConnectionStatus::Inactive;
        $model->save();
        $this->dispatch('toast', message: 'Connection disabled.');
    }

    public function deleteConnection(): mixed
    {
        // No service delete exists; the env-scoped model is removed directly.
        $this->connection()->delete();

        $this->dispatch('toast', message: 'Connection deleted.');

        return $this->redirectRoute('environment.connections', navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $model = $this->connection();

        return [
            'connection' => $model,
            'orgId' => $model->organization_id,
            'orgName' => Organization::query()->whereKey($model->organization_id)->value('name'),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.connections') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Single sign-on</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $connection->name }}</h1>
            <span class="cbx-pill cbx-pill--info">{{ strtoupper($connection->type->value) }}</span>
            <span class="badge {{ $connection->isActive() ? 'badge-success' : '' }}">{{ $connection->isActive() ? 'Active' : ucfirst($connection->status->value) }}</span>
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $connection->id }}</p>
    </div>

    {{-- Owning organization --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Organization</p>
        @if ($orgName !== null)
            <a href="{{ route('environment.organizations.show', $orgId) }}" class="mt-2 inline-block text-sm font-medium" style="color:var(--accent)">{{ $orgName }}</a>
        @else
            <p class="mt-2 text-sm mono" style="color:var(--faint)">{{ $orgId }}</p>
        @endif
    </div>

    {{-- Configuration --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Configuration</p>
        <form wire:submit="saveConfig" class="mt-4 space-y-4">
            <div>
                <label class="label" for="editName">Connection name</label>
                <input wire:model="editName" id="editName" type="text" class="input">
                @error('editName') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            @if ($connection->type === ConnectionType::Saml)
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="idp_entity_id">IdP entity ID</label>
                        <input wire:model="idp_entity_id" id="idp_entity_id" type="text" class="input mono">
                        @error('idp_entity_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="idp_sso_url">IdP SSO URL</label>
                        <input wire:model="idp_sso_url" id="idp_sso_url" type="url" class="input mono">
                        @error('idp_sso_url') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="sp_entity_id">SP entity ID</label>
                        <input wire:model="sp_entity_id" id="sp_entity_id" type="text" class="input mono">
                        @error('sp_entity_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="sp_acs_url">SP ACS URL</label>
                        <input wire:model="sp_acs_url" id="sp_acs_url" type="url" class="input mono">
                        @error('sp_acs_url') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label" for="idp_x509cert">IdP X.509 certificate <span style="color:var(--faint);font-weight:400">— leave blank to keep the current certificate</span></label>
                    <textarea wire:model="idp_x509cert" id="idp_x509cert" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                    @error('idp_x509cert') <p class="field-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <p class="label">Connection ACS URL</p>
                    <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ url("/sso/saml/{$connection->id}/acs") }}</p>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="issuer">Issuer</label>
                        <input wire:model="issuer" id="issuer" type="url" class="input mono">
                        @error('issuer') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="client_id">Client ID</label>
                        <input wire:model="client_id" id="client_id" type="text" class="input mono">
                        @error('client_id') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="client_secret">Client secret <span style="color:var(--faint);font-weight:400">— leave blank to keep</span></label>
                        <input wire:model="client_secret" id="client_secret" type="password" class="input mono" placeholder="••••••••">
                        @error('client_secret') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label" for="signing_key">Signing key <span style="color:var(--faint);font-weight:400">— leave blank to keep the current key</span></label>
                    <textarea wire:model="signing_key" id="signing_key" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN PUBLIC KEY-----"></textarea>
                    @error('signing_key') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveConfig">Save changes</button>
        </form>
    </div>

    {{-- Status & lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Status &amp; lifecycle</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">A draft connection is not yet used for sign-in. Enable it to route matching users through this IdP.</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($connection->isActive())
                <button type="button" class="btn btn-ghost btn-sm" wire:click="disable" wire:confirm="Disable this connection? Users can no longer sign in through it.">Disable</button>
            @else
                <button type="button" class="btn btn-primary btn-sm" wire:click="activate"><x-icon name="check" class="w-4 h-4" /> Enable</button>
            @endif
            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--destructive)" wire:click="deleteConnection" wire:confirm="Permanently delete this connection? This cannot be undone.">Delete connection</button>
        </div>
    </div>
</div>
