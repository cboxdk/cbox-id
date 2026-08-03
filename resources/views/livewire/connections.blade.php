<?php

declare(strict_types=1);

use App\Platform\VerifiedEmailGate;
use App\Platform\AdminPortal;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use App\Platform\Enums\PortalScope;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\DomainAlreadyClaimed;
use Cbox\Id\Federation\Exceptions\OidcDiscoveryFailed;
use Cbox\Id\Federation\Exceptions\SamlMetadataImportFailed;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Federation\OidcDiscovery;
use Cbox\Id\Federation\Saml\SamlMetadataImporter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.console', ['title' => 'Single sign-on'])] class extends Component
{
    public bool $creating = false;

    /** The Admin Portal setup URL, shown to the admin exactly once after minting. */
    public ?string $portalUrl = null;

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

    // Verified domains
    public string $domain = '';

    /** DNS instructions for the domain just added — shown once so the admin can publish the TXT record. */
    public ?string $dnsHost = null;

    public ?string $dnsToken = null;

    public ?string $dnsDomain = null;

    public function create(Connections $connections): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();
        app(VerifiedEmailGate::class)->require('create an identity connection');

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

            try {
                $config = array_merge($config, app(OidcDiscovery::class)->fromIssuer($this->issuer)->toConfig());
            } catch (OidcDiscoveryFailed|UnsafeFederationUrl $e) {
                $this->addError('issuer', "Couldn't read the provider's OpenID configuration — check the issuer URL. ({$e->getMessage()})");

                return;
            }
        }

        $connections->create($this->orgId(), $type, $this->name, $config);

        $this->reset(
            'creating', 'name', 'idp_entity_id', 'idp_sso_url', 'idp_x509cert',
            'sp_entity_id', 'sp_acs_url', 'issuer', 'client_id', 'client_secret', 'signing_key',
        );
        $this->type = 'saml';
        $this->dispatch('toast', message: 'Connection created as a draft.');
    }

    /**
     * Prefill the SAML fields from an IdP's metadata — either pasted XML or a
     * metadata URL. Parsed by the vetted framework importer; only the IdP fields
     * are filled, and the admin still reviews and submits.
     */
    public function importMetadata(SamlMetadataImporter $importer): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();

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

    public function activate(string $id, Connections $connections): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();

        $connections->activate($this->orgId(), $id);
        $this->dispatch('toast', message: 'Connection activated.');
    }

    /**
     * Mint a single-use Admin Portal link and reveal its URL once, so the admin
     * can hand SSO setup to an external IT admin without granting them an account.
     */
    public function invite(AdminPortal $portal): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();

        $token = $portal->generate($this->orgId(), PortalScope::Sso, app(CurrentUser::class)->id());
        $this->portalUrl = route('portal.enter', $token);
    }

    /**
     * Register a domain for this org and mint its DNS challenge. The instructions
     * (challenge host + token) are surfaced once so the admin can publish the TXT.
     */
    public function addDomain(DomainVerification $domains): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();

        $this->domain = strtolower(trim($this->domain));

        $this->validate([
            // A real, dotted hostname — lowercased, no scheme, no path, no '@'.
            'domain' => ['required', 'string', 'max:253', 'regex:/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
        ], [
            'domain.regex' => 'Enter a valid domain, e.g. acme.com.',
        ]);

        try {
            $record = $domains->add($this->orgId(), $this->domain);
        } catch (DomainAlreadyClaimed) {
            $this->addError('domain', 'That domain is already claimed by another organization.');

            return;
        }

        $this->dnsHost = $domains->challengeHost($record->domain);
        $this->dnsToken = $record->verification_token;
        $this->dnsDomain = $record->domain;
        $this->domain = '';
    }

    /**
     * Re-check the DNS TXT record for a domain this org owns and mark it verified.
     */
    public function verifyDomain(string $id, DomainVerification $domains): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();
        $this->ownedDomain($id, $domains);

        if ($domains->verify($id)) {
            $this->dispatch('toast', message: 'Domain verified.');

            return;
        }

        $this->dispatch('toast', message: "We couldn't find the TXT record yet — DNS can take a few minutes.", severity: 'error');
    }

    /**
     * Toggle the capture gate on a VERIFIED domain this org owns.
     */
    public function toggleCapture(string $id, DomainVerification $domains): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();
        $domain = $this->ownedDomain($id, $domains);

        // Capture only makes sense once control of the domain is proven.
        abort_unless($domain->isVerified(), 403);

        $domains->setCapture($id, ! $domain->capture);
        $this->dispatch('toast', message: $domain->capture ? 'Capture disabled.' : 'Capture enabled — matching users must use SSO.');
    }

    public function removeDomain(string $id, DomainVerification $domains): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();
        $this->ownedDomain($id, $domains);

        $domains->remove($id);
        $this->dispatch('toast', message: 'Domain removed.');
    }

    /**
     * Resolve a domain the CURRENT org owns, or refuse. The contract is already
     * org-scoped, but resolving through forOrganization() means a foreign domain id
     * simply never matches — closing cross-org id tampering (deny-by-default).
     */
    private function ownedDomain(string $id, DomainVerification $domains): VerifiedDomain
    {
        foreach ($domains->forOrganization($this->orgId()) as $domain) {
            if ($domain->id === $id) {
                return $domain;
            }
        }

        abort(403);
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            // The view's own authorization question, asked through the scope so it gets
            // the same answer on both planes. It used to read CurrentUser::isAdmin()
            // directly in ten places — a question only the organization plane can answer,
            // so on the environment plane every admin control silently disappeared and
            // the page rendered as an empty read-only shell.
            'mayAdminister' => app(ConsoleScope::class)->mayAdminister(),
            // "No organization chosen" is a real state on the environment plane and must
            // not be reported as "not entitled" — entitled() answers false either way,
            // and telling an administrator to contact their account team when they simply
            // have not picked an organization sends them somewhere useless.
            'needsOrganization' => app(ConsoleScope::class)->organizationId() === null,
            'entitled' => app(ConsoleScope::class)->entitled('sso'),
            'connections' => Connection::query()
                ->where('organization_id', $this->orgId())
                ->orderByDesc('created_at')
                ->get(),
            'domains' => app(DomainVerification::class)->forOrganization($this->orgId()),
        ];
    }

    /**
     * The organization this page is acting on, or '' when the environment plane has not
     * chosen one.
     *
     * Empty rather than a refusal because this is the READ path: the page must render so
     * an administrator can use the picker. Writes cannot slip through on an empty id —
     * guardEntitled() below refuses when no organization is resolved, and it runs before
     * every mutating action.
     */
    private function orgId(): string
    {
        return app(ConsoleScope::class)->organizationId() ?? '';
    }

    public function boot(): void
    {
        // Read gate re-checked on EVERY request, not just first mount: boot() runs on
        // each hydration, so an admin demoted mid-session cannot keep re-rendering
        // org-wide config (SSO connection settings, secrets) from a stale snapshot.
        // These pages expose admin-only data, so the render path itself is gated.
        $this->authorizeAdmin();
    }

    /**
     * Through ConsoleScope, so this page is the SAME page on both planes.
     *
     * It used to read CurrentUser::isAdmin() directly, which is a question only the
     * organization plane can answer — and is why an environment-plane copy of this page
     * had to exist at all. The copy then drifted: it never grew domain verification, the
     * Admin Portal, or an entitlement check.
     */
    private function authorizeAdmin(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    /**
     * Deny-by-default entitlement gate for every mutating action. Runs BEFORE the
     * admin check, so a direct Livewire call from a non-entitled org is refused
     * even though the (upsell) screen itself is reachable.
     */
    private function guardEntitled(): void
    {
        // Also the guard that stops a write with no organization chosen: entitled()
        // answers false when nothing is resolved rather than defaulting open.
        app(ConsoleScope::class)->assertEntitled('sso');
    }
}; ?>

<div>
    <x-page-header title="Single sign-on" :help="\App\Platform\Help\HelpTopic::SingleSignOn"
                   subtitle="Let people sign in with the company account they already have, instead of a separate password here.">
        @if ($mayAdminister && $entitled)
            <x-slot:actions>
                <button wire:click="invite" class="btn btn-ghost"><x-icon name="members" class="w-4 h-4" /> Invite your IT admin</button>
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New connection</button>
            </x-slot:actions>
        @endif
    </x-page-header>

    <div class="mt-8 space-y-6">

    @if ($needsOrganization)
        <div class="card">
            <x-empty-state icon="layers" title="Choose an organization"
                           body="This console administers every organization in the environment, so pick the one you want to configure single sign-on for — the selector sits in the bar at the top of the page." />
        </div>
    @elseif (! $entitled)
        <div class="card">
            <x-empty-state icon="connections" title="Single sign-on is an Enterprise feature"
                           :help="\App\Platform\Help\HelpTopic::SingleSignOn"
                           body="Letting your people sign in with Entra ID, Okta or Google Workspace is available on the Enterprise plan. Contact your account team to enable it for this organization." />
        </div>
    @else

    @if ($portalUrl && $mayAdminister)
        <div class="card p-5" style="border-color:color-mix(in oklch, var(--accent) 40%, transparent)">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 font-semibold"><x-icon name="members" class="w-4 h-4" /> Setup link for your IT admin</div>
                    <p class="mt-1 text-sm" style="color:var(--muted-foreground)">Send this single-use link to whoever configures your identity provider. It expires soon and works without an account. Copy it now — it is shown only once.</p>
                </div>
                <button wire:click="$set('portalUrl', null)" class="btn btn-ghost btn-sm">Done</button>
            </div>
            <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ $portalUrl }}</p>
        </div>
    @endif

    @if ($creating && $mayAdminister)
        <form wire:submit="create" class="card p-5 space-y-4">
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
                {{-- Onboarding shortcut: paste the IdP's metadata (or its URL) to fill
                     the three IdP fields below in one step, instead of copying by hand. --}}
                <div class="rounded-xl p-4" style="background:var(--secondary);border:1px solid var(--border)">
                    <label class="label" for="metadataInput">Import from IdP metadata <span style="color:var(--muted);font-weight:400">— optional</span></label>
                    <textarea wire:model="metadataInput" id="metadataInput" rows="2" class="input mono" style="font-size:0.78rem"
                              placeholder="Paste the IdP metadata XML, or a metadata URL (https://idp.example.com/metadata)" @error('metadataInput') aria-invalid="true" aria-describedby="metadataInput-error" @enderror></textarea>
                    @error('metadataInput') <p id="metadataInput-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    <button type="button" wire:click="importMetadata" class="btn btn-secondary btn-sm mt-2"
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
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create connection</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="space-y-4">
        @forelse ($connections as $c)
            <div wire:key="connection-{{ $c->id }}" class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-semibold truncate">{{ $c->name }}</p>
                            <span class="cbx-pill">{{ strtoupper($c->type->value) }}</span>
                            @if ($c->isActive())
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span> Active</span>
                            @else
                                <span class="cbx-pill"><span class="dot"></span> {{ ucfirst($c->status->value) }}</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs mono truncate" style="color:var(--muted-foreground)">{{ $c->id }}</p>
                    </div>
                    @if ($mayAdminister && ! $c->isActive())
                        <button wire:click="activate('{{ $c->id }}')" class="btn btn-primary btn-sm"><x-icon name="check" class="w-4 h-4" /> Activate</button>
                    @endif
                </div>

                @if ($c->type === \Cbox\Id\Federation\Enums\ConnectionType::Saml)
                    <div class="mt-4">
                        <p class="label">ACS URL</p>
                        <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ url("/sso/saml/{$c->id}/acs") }}</p>
                    </div>
                @endif
            </div>
        @empty
            <div class="card">
                <x-empty-state icon="connections" title="No identity provider connected yet"
                               :help="\App\Platform\Help\HelpTopic::SingleSignOn"
                               body="Right now people sign in with credentials held here. Connect your provider and they use the company account they already have — and lose access here the moment you disable it there."
                               :steps="[
                                   'In Entra ID, Okta or Google Workspace, create an application for Cbox ID.',
                                   'Add the connection below — paste the provider’s metadata and the fields fill themselves.',
                                   'Verify the email domains you own, so your people are routed to your provider automatically.',
                                   'Activate the connection once a test sign-in works.',
                               ]">
                    @if ($mayAdminister)
                        <x-slot:actions>
                            <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New connection</button>
                            <button wire:click="invite" class="btn btn-ghost"><x-icon name="members" class="w-4 h-4" /> Hand it to your IT admin</button>
                        </x-slot:actions>
                    @endif
                </x-empty-state>
            </div>
        @endforelse
    </div>

    {{-- Verified domains — DNS-proven ownership powers home-realm discovery and the optional capture gate. --}}
    <div class="space-y-4">
        <div>
            <h2 class="cbx-panel-title" style="font-size:18px">Verified domains</h2>
            <p class="mt-1 text-sm" style="color:var(--muted-foreground)">Prove ownership of an email domain to route your team to SSO automatically.</p>
        </div>

        @if ($mayAdminister)
            <form wire:submit="addDomain" class="card p-5 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[14rem]">
                    <label class="label" for="domain">Domain</label>
                    <input wire:model="domain" id="domain" type="text" inputmode="url" autocapitalize="none" spellcheck="false" class="input mono" placeholder="acme.com" @error('domain') aria-invalid="true" aria-describedby="domain-error" @enderror>
                    @error('domain') <p id="domain-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="addDomain"><x-icon name="plus" class="w-4 h-4" /> Add domain</button>
            </form>
        @endif

        @if ($dnsHost && $dnsToken && $mayAdminister)
            <div class="card p-5" style="border-color:color-mix(in oklch, var(--accent) 40%, transparent)">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 font-semibold"><x-icon name="connections" class="w-4 h-4" /> Verify {{ $dnsDomain }}</div>
                        <p class="mt-1 text-sm" style="color:var(--muted-foreground)">Add a TXT record at <code class="mono">{{ $dnsHost }}</code> with the value below, then click Verify. DNS changes can take a few minutes to propagate.</p>
                    </div>
                    <button wire:click="$set('dnsHost', null)" class="btn btn-ghost btn-sm">Done</button>
                </div>
                <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--secondary);border:1px solid var(--border)">{{ $dnsToken }}</p>
            </div>
        @endif

        <div class="space-y-3">
            @forelse ($domains as $d)
                <div wire:key="directory-{{ $d->id }}" class="card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="font-semibold truncate mono">{{ $d->domain }}</p>
                                @if ($d->verified_at)
                                    <span class="cbx-pill cbx-pill--success"><span class="dot"></span> Verified</span>
                                @else
                                    <span class="cbx-pill cbx-pill--warning"><span class="dot"></span> Pending</span>
                                @endif
                                @if ($d->capture)
                                    <span class="cbx-pill cbx-pill--info"><span class="dot"></span> Capture on</span>
                                @endif
                            </div>
                        </div>
                        @if ($mayAdminister)
                            <div class="flex items-center gap-2">
                                @unless ($d->verified_at)
                                    <button wire:click="verifyDomain('{{ $d->id }}')" class="btn btn-primary btn-sm"><x-icon name="check" class="w-4 h-4" /> Verify</button>
                                @endunless
                                <x-confirm-delete
                                    :name="$d->domain"
                                    :action="'removeDomain(\''.$d->id.'\')'"
                                    label="Remove"
                                    consequence="This domain will no longer route its users through this org's SSO. This cannot be undone." />
                            </div>
                        @endif
                    </div>

                    @if ($d->verified_at && $mayAdminister)
                        <div class="mt-4 flex items-start justify-between gap-3 rounded-lg px-3 py-3" style="background:var(--secondary)">
                            <div class="min-w-0">
                                <p class="text-sm font-medium">Capture</p>
                                <p class="mt-0.5 text-xs" style="color:var(--muted-foreground)">Force everyone with an @{{ $d->domain }} email to sign in through this org's SSO.</p>
                            </div>
                            <button wire:click="toggleCapture('{{ $d->id }}')" class="btn {{ $d->capture ? 'btn-primary' : 'btn-ghost' }} btn-sm">
                                {{ $d->capture ? 'On' : 'Off' }}
                            </button>
                        </div>
                    @endif
                </div>
            @empty
                <div class="card">
                    <x-empty-state icon="directory" title="No verified domains yet"
                                   body="Verifying a domain you own — acme.com — lets Cbox ID recognise your people by their email address and send them straight to your provider, so nobody has to pick the right sign-in button." />
                </div>
            @endforelse
        </div>
    </div>
    @endif
    </div>
</div>
