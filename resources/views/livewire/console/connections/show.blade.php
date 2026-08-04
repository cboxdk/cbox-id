<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Entitlements;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\OidcDiscoveryFailed;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\OidcDiscovery;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Single sign-on › detail — one component, both planes. The full,
 * deep-linkable lifecycle for one connection: edit the IdP config, enable/disable and
 * delete.
 *
 * This page is what the organization plane GAINED in the merge, and it is where the
 * merge could most easily have gone wrong. The environment version resolved a connection
 * on its primary key alone, which was safe only because an environment administrator
 * sits above every organization here. Serving the same page to a tenant admin would hand
 * them every other tenant's connection by id — and `saveConfig` on one of those rewrites
 * where another company's people authenticate, while the sealed config it edits holds
 * their client secrets and signing certificates. So every read and every mutation
 * re-resolves through the acting organization and 404s otherwise: an id that is not this
 * administrator's never matches (deny-by-default), and 404 rather than 403 because the
 * caller was not entitled to learn it exists.
 *
 * The IdP config is sealed at rest: it is decrypted only to prefill the editable fields,
 * certificates and signing keys are never echoed back, and a save re-seals through the
 * Crypto kernel exactly as creation does.
 */
new #[Layout('components.layouts.console', ['title' => 'SSO connection'])] class extends Component
{
    /**
     * Read gate re-checked on EVERY request, not just first mount: boot() runs on each
     * hydration, so an admin demoted mid-session cannot keep re-rendering another
     * organization's IdP configuration from a stale snapshot.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
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
        $this->connectionId = $connection;

        // Resolves through the same scoping rule every later read uses, so an id the
        // acting administrator may not see 404s here rather than at the first action.
        $model = $this->connection();

        $this->editName = $model->name;
        $this->hydrateConfig($model, $connections);
    }

    /**
     * The connection, re-resolved and re-scoped on every read and write.
     *
     * The organization narrowing is the half the environment copy never needed. A
     * connection always belongs to exactly one organization (`organization_id` is
     * non-null by schema), so there is no environment-owned row to make visible to a
     * tenant the way inline hooks and conflict rules have — the acting organization is
     * simply the boundary.
     */
    private function connection(): Connection
    {
        $organizationId = $this->actingOrganizationId();

        $model = Connection::query()
            ->whereKey($this->connectionId)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->first();

        abort_if($model === null, 404);

        return $model;
    }

    /**
     * The organization this page is acting on, or null for an environment administrator
     * who has chosen none.
     *
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and on
     * the organization plane that second case would unscope the lookup entirely, which
     * is precisely the escalation this page exists to avoid.
     */
    private function actingOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Environment
            ? $scope->organizationId()
            : $scope->requireOrganizationId();
    }

    /**
     * Prefill the editable IdP fields from the sealed config. Certificates and signing
     * keys are deliberately NOT prefilled — a sealed secret is never returned to the
     * browser; leaving the field blank on save preserves the stored value.
     */
    private function hydrateConfig(Connection $model, Connections $connections): void
    {
        $config = $this->safeConfig($model, $connections);

        $this->idp_entity_id = $this->configString($config, 'idp_entity_id');
        $this->idp_sso_url = $this->configString($config, 'idp_sso_url');
        $this->sp_entity_id = $this->configString($config, 'sp_entity_id');
        $this->sp_acs_url = $this->configString($config, 'sp_acs_url');
        $this->issuer = $this->configString($config, 'issuer');
        $this->client_id = $this->configString($config, 'client_id');
    }

    /**
     * One string field out of the decrypted config. The seal holds free-form JSON, so
     * a value can be of any shape; anything that is not a string is treated as absent
     * rather than coerced into one.
     *
     * @param  array<string, mixed>  $config
     */
    private function configString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : '';
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
        $model = $this->guardEntitled();

        $this->validate(['editName' => ['required', 'string', 'max:120']]);

        // Start from the sealed config so any secret left blank is carried through.
        $current = $this->safeConfig($model, $connections);

        if ($model->type === ConnectionType::Saml) {
            $this->validate([
                'idp_entity_id' => 'required|string|max:500',
                'idp_sso_url' => 'required|url|max:500',
                'sp_entity_id' => 'required|string|max:500',
                'sp_acs_url' => 'required|url|max:500',
            ]);

            $cert = trim($this->idp_x509cert) !== '' ? trim($this->idp_x509cert) : $this->configString($current, 'idp_x509cert');
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

            $key = trim($this->signing_key) !== '' ? trim($this->signing_key) : $this->configString($current, 'signing_key');
            if ($key === '') {
                $this->addError('signing_key', 'A signing key is required.');

                return;
            }

            // Secrets are write-once: a blank field keeps the sealed value.
            $secret = trim($this->client_secret) !== '' ? trim($this->client_secret) : $this->configString($current, 'client_secret');
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

        $model->name = trim($this->editName);
        $model->config_encrypted = $secretBox->seal(
            json_encode($config, JSON_THROW_ON_ERROR),
            $model->secretContext(),
        );
        $model->save();

        // Never keep a secret in component state once it has been sealed.
        $this->reset('idp_x509cert', 'signing_key', 'client_secret');

        $this->dispatch('toast', message: 'Connection updated.');
    }

    /**
     * Whether to put the SSO mandate in front of the administrator on this render.
     *
     * Set when a connection is activated and never at mount, which is the whole design: a
     * control nobody finds is a control nobody uses, and the ONE moment somebody has
     * demonstrably decided how their company should sign in is the moment they finish
     * connecting the identity provider. Anywhere else it would be a switch on a settings
     * page they have no reason to open.
     */
    public bool $offerMandate = false;

    public function activate(Connections $connections): void
    {
        $model = $this->guardEntitled();

        // The service scopes the flip to the owning org, so a draft can't be activated
        // across tenants.
        $connections->activate($model->organization_id, $model->id);

        // Offered, not applied. Tightening the mandate ends every password session in the
        // organization — {@see \App\Platform\RevokingAuthPolicies::setForOrganization()}
        // does that on the way through the contract — and the administrator who just
        // pressed Enable is very likely holding one of them. A side effect that signs
        // somebody out mid-task is not something to spring on them.
        $this->offerMandate = true;

        $this->dispatch('toast', message: 'Connection activated.');
    }

    /**
     * Turn the mandate on for this connection's organization.
     *
     * Written through the {@see AuthPolicies} contract, which is what makes the session
     * revocation happen: it lives in a decorator around this interface, so reaching for
     * the concrete store would refuse tomorrow's password logins and leave every session
     * that was opened with one wide open.
     *
     * The new override is the organization's EXISTING policy with the mandate raised —
     * its own override if it has one, otherwise the environment baseline. Starting from a
     * bare {@see AuthPolicy} would write this organization's first override out of the
     * value object's defaults, quietly restating a 12-character minimum for a tenant
     * whose environment asked for 16.
     */
    public function requireSso(AuthPolicies $policies): void
    {
        $model = $this->guardEntitled();

        $current = $policies->overrideFor($model->organization_id) ?? $policies->forEnvironment();

        $policies->setForOrganization($model->organization_id, new AuthPolicy(
            minLength: $current->minLength,
            requireBreachCheck: $current->requireBreachCheck,
            maxAgeDays: $current->maxAgeDays,
            reuseHistory: $current->reuseHistory,
            mfa: $current->mfa,
            sso: SsoEnforcement::Required,
            lockoutThreshold: $current->lockoutThreshold,
        ));

        $this->offerMandate = false;

        $this->dispatch('toast', message: 'Single sign-on is now required.');
    }

    /** The decline — one click, and the offer does not come back until the next activation. */
    public function keepPasswords(): void
    {
        $this->offerMandate = false;
    }

    public function disable(): void
    {
        // No service method disables a connection; the status flips on the scoped model
        // directly (mirrors how the organization console persists status changes).
        $model = $this->guardEntitled();
        $model->status = ConnectionStatus::Inactive;
        $model->save();
        $this->dispatch('toast', message: 'Connection disabled.');
    }

    public function deleteConnection(): mixed
    {
        // No service delete exists; the scoped model is removed directly.
        $this->guardEntitled()->delete();

        $this->dispatch('toast', message: 'Connection deleted.');

        return $this->redirectRoute(app(ConsoleScope::class)->routeName('connections'), navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);
        $model = $this->connection();

        // Re-derived rather than trusted from the flag alone. `offerMandate` is a public
        // property and therefore client-settable, and the organization's policy can have
        // been tightened from the Sign-in rules page in another tab since it was set —
        // offering to turn on something already on would be the console lying about state
        // it can simply read.
        $passwordsStillAllowed = app(AuthPolicies::class)
            ->resolve($model->organization_id)->sso->allowsPasswordLogin();

        return [
            'offeringMandate' => $this->offerMandate && $passwordsStillAllowed && $model->isActive(),
            'passwordsStillAllowed' => $passwordsStillAllowed,
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'connection' => $model,
            'orgId' => $model->organization_id,
            'orgName' => Organization::query()->whereKey($model->organization_id)->value('name'),
            // Only the environment console has an organization detail page to link to;
            // on the organization plane there is exactly one organization and no page
            // about it, so the name is text rather than a link that 404s.
            'orgUrl' => $scope->plane() === ConsolePlane::Environment
                ? route('environment.organizations.show', $model->organization_id)
                : null,
            // Gated on BOTH planes, which the environment copy was not. Asked of the
            // connection's own organization for the same reason the write gate is —
            // see guardEntitled().
            'entitled' => app(Entitlements::class)->entitled($model->organization_id, 'sso'),
        ];
    }

    /**
     * The connection, refused unless its organization is entitled to SSO.
     *
     * The environment console had no entitlement check at all, so switching consoles
     * walked past the gate the organization console enforced. It is asked of the
     * CONNECTION's organization rather than the acting one, which the scope's own
     * {@see ConsoleScope::assertEntitled()} would use: an entitlement belongs to the
     * organization whose sign-in this connection governs, and an environment
     * administrator following a deep link has not necessarily chosen one — resolving the
     * gate against a blank selection would refuse a legitimate edit while telling nobody
     * why. On the organization plane the two are the same organization by construction.
     */
    private function guardEntitled(): Connection
    {
        $model = $this->connection();

        if (! app(Entitlements::class)->entitled($model->organization_id, 'sso')) {
            throw new AuthorizationException('This organization does not have access to that feature.');
        }

        return $model;
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route($scopeRoute('connections')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Single sign-on</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $connection->name }}</h1>
            <span class="cbx-pill cbx-pill--info">{{ strtoupper($connection->type->value) }}</span>
            <span class="badge {{ $connection->isActive() ? 'badge-success' : '' }}">{{ $connection->isActive() ? 'Active' : ucfirst($connection->status->value) }}</span>
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $connection->id }}</p>
    </div>

    {{-- Owning organization --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Organization</h2>
        @if ($orgName !== null && $orgUrl !== null)
            <a href="{{ $orgUrl }}" class="mt-2 inline-block text-sm font-medium" style="color:var(--accent-strong)">{{ $orgName }}</a>
        @elseif ($orgName !== null)
            <p class="mt-2 text-sm font-medium">{{ $orgName }}</p>
        @else
            <p class="mt-2 text-sm mono" style="color:var(--faint)">{{ $orgId }}</p>
        @endif
    </div>

    @unless ($entitled)
        <div class="card">
            <x-empty-state icon="connections" title="Single sign-on is an Enterprise feature"
                           :help="\App\Platform\Help\HelpTopic::SingleSignOn"
                           body="This connection can't be changed while the organization is off the Enterprise plan. Contact your account team to enable it." />
        </div>
    @else

    {{-- The mandate, offered at the moment it makes sense to ask.

         This is the only place in the console that asks. An administrator who has just
         connected an identity provider has, by that act, said what they want sign-in to
         be — anywhere else the question is a setting they have no reason to go looking
         for. It is an OFFER: the consequences are on screen before the click, because the
         click ends every password session in the organization and one of them is probably
         theirs. --}}
    @if ($offeringMandate)
        <div role="alert" class="rounded-xl border p-5" style="border-color:var(--accent);background:var(--accent-soft)">
            <h2 class="text-base font-semibold">Make {{ $connection->name }} the only way in?</h2>
            <p class="mt-2 text-sm" style="color:var(--muted)">
                {{ $orgName ?? 'This organization' }} can sign in with passwords or with this connection.
                Requiring single sign-on refuses passwords outright, so an old credential cannot go around the
                provider you just set up. Most companies that connect an IdP want this — but read what it does first.
            </p>
            <ul class="mt-3 space-y-1 text-sm list-disc pl-5" style="color:var(--muted)">
                <li>Password sign-in stops working for everyone in this organization, immediately.</li>
                <li>Every session that was opened with a password ends — <strong>including yours</strong>, if you signed in that way.</li>
                <li>You can turn it off again on Sign-in rules; sessions that ended stay ended.</li>
            </ul>
            <div class="mt-4 flex flex-wrap gap-2">
                <button type="button" wire:click="requireSso" class="btn btn-primary"
                        wire:loading.attr="disabled" wire:target="requireSso">
                    <span wire:loading.remove wire:target="requireSso">Require single sign-on</span>
                    <span wire:loading wire:target="requireSso" class="inline-flex items-center gap-2"><span class="spinner"></span> Applying…</span>
                </button>
                <button type="button" wire:click="keepPasswords" class="btn btn-ghost">Not now — keep passwords working</button>
            </div>
        </div>
    @elseif ($connection->isActive() && ! $passwordsStillAllowed)
        {{-- The other half of the same fact, and it belongs here rather than only on the
             rules page: somebody debugging "why can nobody sign in with a password" is
             looking at the connection, not at a settings page they may not know exists. --}}
        <div class="rounded-xl border p-4 flex flex-wrap items-center justify-between gap-3"
             style="border-color:var(--border);background:var(--surface-2)">
            <p class="text-sm" style="color:var(--muted)">
                <strong style="color:var(--text)">Single sign-on is required</strong> for {{ $orgName ?? 'this organization' }} — password sign-in is refused.
            </p>
            <a href="{{ route($scopeRoute('auth-policy')) }}" class="btn btn-ghost btn-sm">Sign-in rules</a>
        </div>
    @endif

    {{-- Configuration --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Configuration</h2>
        <form wire:submit="saveConfig" class="mt-4 space-y-4">
            <div>
                <label class="label" for="editName">Connection name</label>
                <input wire:model="editName" id="editName" type="text" class="input" @error('editName') aria-invalid="true" aria-describedby="editName-error" @enderror>
                @error('editName') <p id="editName-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            @if ($connection->type === ConnectionType::Saml)
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="idp_entity_id">IdP entity ID</label>
                        <input wire:model="idp_entity_id" id="idp_entity_id" type="text" class="input mono" @error('idp_entity_id') aria-invalid="true" aria-describedby="idp_entity_id-error" @enderror>
                        @error('idp_entity_id') <p id="idp_entity_id-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="idp_sso_url">IdP SSO URL</label>
                        <input wire:model="idp_sso_url" id="idp_sso_url" type="url" class="input mono" @error('idp_sso_url') aria-invalid="true" aria-describedby="idp_sso_url-error" @enderror>
                        @error('idp_sso_url') <p id="idp_sso_url-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="sp_entity_id">SP entity ID</label>
                        <input wire:model="sp_entity_id" id="sp_entity_id" type="text" class="input mono" @error('sp_entity_id') aria-invalid="true" aria-describedby="sp_entity_id-error" @enderror>
                        @error('sp_entity_id') <p id="sp_entity_id-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="sp_acs_url">SP ACS URL</label>
                        <input wire:model="sp_acs_url" id="sp_acs_url" type="url" class="input mono" @error('sp_acs_url') aria-invalid="true" aria-describedby="sp_acs_url-error" @enderror>
                        @error('sp_acs_url') <p id="sp_acs_url-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label" for="idp_x509cert">IdP X.509 certificate <span style="color:var(--faint);font-weight:400">— leave blank to keep the current certificate</span></label>
                    <textarea wire:model="idp_x509cert" id="idp_x509cert" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN CERTIFICATE-----" @error('idp_x509cert') aria-invalid="true" aria-describedby="idp_x509cert-error" @enderror></textarea>
                    @error('idp_x509cert') <p id="idp_x509cert-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <p class="label">Connection ACS URL</p>
                    <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ url("/sso/saml/{$connection->id}/acs") }}</p>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="issuer">Issuer</label>
                        <input wire:model="issuer" id="issuer" type="url" class="input mono" @error('issuer') aria-invalid="true" aria-describedby="issuer-error" @enderror>
                        @error('issuer') <p id="issuer-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="client_id">Client ID</label>
                        <input wire:model="client_id" id="client_id" type="text" class="input mono" @error('client_id') aria-invalid="true" aria-describedby="client_id-error" @enderror>
                        @error('client_id') <p id="client_id-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="client_secret">Client secret <span style="color:var(--faint);font-weight:400">— leave blank to keep</span></label>
                        <input wire:model="client_secret" id="client_secret" type="password" class="input mono" placeholder="••••••••" autocomplete="off" @error('client_secret') aria-invalid="true" aria-describedby="client_secret-error" @enderror>
                        @error('client_secret') <p id="client_secret-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label class="label" for="signing_key">Signing key <span style="color:var(--faint);font-weight:400">— leave blank to keep the current key</span></label>
                    <textarea wire:model="signing_key" id="signing_key" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN PUBLIC KEY-----" @error('signing_key') aria-invalid="true" aria-describedby="signing_key-error" @enderror></textarea>
                    @error('signing_key') <p id="signing_key-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            @endif

            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveConfig">Save changes</button>
        </form>
    </div>

    {{-- Status & lifecycle --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Status &amp; lifecycle</h2>
        <p class="mt-1 text-sm" style="color:var(--muted)">A draft connection is not yet used for sign-in. Enable it to route matching users through this IdP.</p>
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($connection->isActive())
                <x-confirm-delete
                    :name="$connection->name"
                    action="disable"
                    label="Disable"
                    trigger-class="btn btn-ghost btn-sm"
                    consequence="Everyone who signs in through this connection is locked out until it is re-enabled." />
            @else
                <button type="button" class="btn btn-primary btn-sm" wire:click="activate"><x-icon name="check" class="w-4 h-4" /> Enable</button>
            @endif
            <x-confirm-delete
                :name="$connection->name"
                action="deleteConnection"
                label="Delete connection"
                consequence="Users routed through this IdP can no longer sign in. This cannot be undone." />
        </div>
    </div>
    @endunless
</div>
