<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\VerifiedEmailGate;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\OidcDiscoveryFailed;
use Cbox\Id\Federation\Exceptions\SamlMetadataImportFailed;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\OidcDiscovery;
use Cbox\Id\Federation\Saml\SamlMetadataImporter;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Console › Single sign-on › New — one component, both planes. A dedicated,
 * deep-linkable create page carrying the full IdP config (SAML or OIDC). On success we
 * route straight to the new connection's detail page, where it starts life as a draft.
 *
 * The organization picker is gone. The environment plane's form carried one and the
 * organization plane's did not — a second copy of an answer the console chrome already
 * holds, and the reason the two planes came to validate it differently. Unlike the other
 * merged capabilities there is no "All organizations" option hiding in it: a
 * {@see Cbox\Id\Federation\Models\Connection} has a non-null `organization_id` by
 * schema, so every option in that select really was an organization and nothing is lost
 * with it.
 *
 * Gated on the `sso` entitlement, which the environment plane never checked at all —
 * an entitlement belongs to the organization, not to the door.
 */
new #[Layout('components.layouts.console', ['title' => 'New connection'])] class extends Component
{
    /**
     * Read gate re-checked on EVERY request, not just first mount: boot() runs on each
     * hydration, so an admin demoted mid-session cannot keep re-rendering this form
     * from a stale snapshot.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

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
        $this->guardEntitled();

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

        $this->dispatch('toast', message: 'Metadata imported — review the fields and create the connection.');
    }

    public function create(Connections $connections): mixed
    {
        $scope = app(ConsoleScope::class);

        try {
            $organizationId = $scope->requireOrganizationId();
        } catch (AuthorizationException $e) {
            // Reported on the name field rather than thrown: on the environment plane
            // "you have not picked an organization yet" is an ordinary state of the
            // console, not a failure, and the form must survive to be resubmitted.
            $this->addError('name', $e->getMessage());

            return null;
        }

        // Deny-by-default entitlement gate, which the environment plane never had. It
        // runs before the write and after the organization is resolved, because the
        // entitlement is that organization's.
        $scope->assertEntitled('sso');
        $scope->assertMayAdminister();

        if ($scope->plane() === ConsolePlane::Organization) {
            // The unconfirmed-address hold is a SUBJECT-plane rule: it exists because a
            // social sign-in hands us an address the provider merely passed along, and it
            // holds the durable objects that address could then be trusted for. An
            // environment administrator is an account member with no subject address in
            // this session at all, so asking the gate about them answers "unverified" for
            // everyone and would refuse every creation on that plane outright.
            app(VerifiedEmailGate::class)->require('create an identity connection');
        }

        $type = ConnectionType::from($this->type);

        if ($type === ConnectionType::Saml) {
            /** @var array<string, mixed> $config */
            $config = $this->validate([
                'idp_entity_id' => 'required|string|max:500',
                'idp_sso_url' => 'required|url|max:500',
                'idp_x509cert' => 'required|string',
                'sp_entity_id' => 'required|string|max:500',
                'sp_acs_url' => 'required|url|max:500',
            ]);
        } else {
            /** @var array<string, mixed> $config */
            $config = $this->validate([
                'issuer' => 'required|url|max:500',
                'client_id' => 'required|string|max:500',
                'client_secret' => 'required|string|max:500',
                'signing_key' => 'required|string',
            ]);

            // Resolve the provider's authorization/token endpoints from its issuer
            // (SSRF-guarded discovery) so the connection is complete — OidcClient needs
            // them at redirect time, and an issuer alone would dead-end mid-flow.
            try {
                $config = array_merge($config, app(OidcDiscovery::class)->fromIssuer($this->issuer)->toConfig());
            } catch (OidcDiscoveryFailed|UnsafeFederationUrl $e) {
                $this->addError('issuer', "Couldn't read the provider's OpenID configuration — check the issuer URL. ({$e->getMessage()})");

                return null;
            }
        }

        $connection = $connections->create($organizationId, $type, trim($this->name), $config);

        // Never keep a secret in component state once it has been sealed.
        $this->reset('idp_x509cert', 'signing_key', 'client_secret');

        $this->dispatch('toast', message: 'Connection created as a draft.');

        return $this->redirectRoute(
            $scope->routeName('connections.show'),
            ['connection' => $connection->id],
            navigate: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            // "No organization chosen" is a real state on the environment plane and must
            // not be reported as "not entitled" — entitled() answers false either way.
            'needsOrganization' => $scope->organizationId() === null,
            'entitled' => $scope->entitled('sso'),
        ];
    }

    /**
     * Deny-by-default entitlement gate for a mutating action. Also the guard that stops a
     * write with no organization chosen: entitled() answers false when nothing is
     * resolved rather than defaulting open.
     */
    private function guardEntitled(): void
    {
        app(ConsoleScope::class)->assertEntitled('sso');
    }
}; ?>

<div>
    <a href="{{ route($scopeRoute('connections')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Single sign-on</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New connection</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Configure a SAML or OIDC identity provider for the organization you are administering. It starts as a draft.</p>

    @if ($needsOrganization)
        <div class="card mt-6">
            <x-empty-state icon="layers" title="Choose an organization"
                           body="A connection belongs to one organization, so pick the one you are configuring — the selector sits in the bar at the top of the page." />
        </div>
    @elseif (! $entitled)
        <div class="card mt-6">
            <x-empty-state icon="connections" title="Single sign-on is an Enterprise feature"
                           :help="\App\Platform\Help\HelpTopic::SingleSignOn"
                           body="Letting your people sign in with Entra ID, Okta or Google Workspace is available on the Enterprise plan. Contact your account team to enable it for this organization." />
        </div>
    @else
    <form wire:submit="create" class="mt-6 max-w-2xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Connection name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="Acme Okta" autofocus @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name') <p id="name-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="type">Protocol</label>
                <select wire:model.live="type" id="type" class="select" @error('type') aria-invalid="true" aria-describedby="type-error" @enderror>
                    <option value="saml">SAML 2.0</option>
                    <option value="oidc">OpenID Connect</option>
                </select>
                @error('type') <p id="type-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        @if ($type === 'saml')
            {{-- Onboarding shortcut: paste the IdP's metadata (or its URL) to fill the
                 three IdP fields below in one step, instead of copying by hand. --}}
            <div class="rounded-xl p-4" style="background:var(--surface-2);border:1px solid var(--border)">
                <label class="label" for="metadataInput">Import from IdP metadata <span style="color:var(--faint);font-weight:400">— optional</span></label>
                <textarea wire:model="metadataInput" id="metadataInput" rows="2" class="input mono" style="font-size:0.78rem"
                          placeholder="Paste the IdP metadata XML, or a metadata URL (https://idp.example.com/metadata)" @error('metadataInput') aria-invalid="true" aria-describedby="metadataInput-error" @enderror></textarea>
                @error('metadataInput') <p id="metadataInput-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                <button type="button" wire:click="importMetadata" class="btn btn-ghost btn-sm mt-2"
                        wire:loading.attr="disabled" wire:target="importMetadata">Prefill from metadata</button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="idp_entity_id">IdP entity ID</label>
                    <input wire:model="idp_entity_id" id="idp_entity_id" type="text" class="input mono" placeholder="https://idp.example.com/metadata" @error('idp_entity_id') aria-invalid="true" aria-describedby="idp_entity_id-error" @enderror>
                    @error('idp_entity_id') <p id="idp_entity_id-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="idp_sso_url">IdP SSO URL</label>
                    <input wire:model="idp_sso_url" id="idp_sso_url" type="url" class="input mono" placeholder="https://idp.example.com/sso" @error('idp_sso_url') aria-invalid="true" aria-describedby="idp_sso_url-error" @enderror>
                    @error('idp_sso_url') <p id="idp_sso_url-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="sp_entity_id">SP entity ID</label>
                    <input wire:model="sp_entity_id" id="sp_entity_id" type="text" class="input mono" placeholder="https://cbox-id/sp" @error('sp_entity_id') aria-invalid="true" aria-describedby="sp_entity_id-error" @enderror>
                    @error('sp_entity_id') <p id="sp_entity_id-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="sp_acs_url">SP ACS URL</label>
                    <input wire:model="sp_acs_url" id="sp_acs_url" type="url" class="input mono" placeholder="https://cbox-id/sso/saml/…/acs" @error('sp_acs_url') aria-invalid="true" aria-describedby="sp_acs_url-error" @enderror>
                    @error('sp_acs_url') <p id="sp_acs_url-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="idp_x509cert">IdP X.509 certificate</label>
                <textarea wire:model="idp_x509cert" id="idp_x509cert" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN CERTIFICATE-----" @error('idp_x509cert') aria-invalid="true" aria-describedby="idp_x509cert-error" @enderror></textarea>
                @error('idp_x509cert') <p id="idp_x509cert-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="issuer">Issuer</label>
                    <input wire:model="issuer" id="issuer" type="url" class="input mono" placeholder="https://idp.example.com" @error('issuer') aria-invalid="true" aria-describedby="issuer-error" @enderror>
                    @error('issuer') <p id="issuer-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="client_id">Client ID</label>
                    <input wire:model="client_id" id="client_id" type="text" class="input mono" placeholder="cbox-id-app" @error('client_id') aria-invalid="true" aria-describedby="client_id-error" @enderror>
                    @error('client_id') <p id="client_id-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="client_secret">Client secret</label>
                    <input wire:model="client_secret" id="client_secret" type="password" class="input mono" placeholder="••••••••" autocomplete="off" @error('client_secret') aria-invalid="true" aria-describedby="client_secret-error" @enderror>
                    @error('client_secret') <p id="client_secret-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="signing_key">Signing key</label>
                <textarea wire:model="signing_key" id="signing_key" rows="4" class="input mono" style="font-size:0.78rem" placeholder="-----BEGIN PUBLIC KEY-----" @error('signing_key') aria-invalid="true" aria-describedby="signing_key-error" @enderror></textarea>
                @error('signing_key') <p id="signing_key-error" class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @endif

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create connection</button>
            <a href="{{ route($scopeRoute('connections')) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
    @endif
</div>
