<?php

declare(strict_types=1);

use App\Platform\AdminPortal;
use App\Platform\CurrentUser;
use App\Platform\Entitlements;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\DomainAlreadyClaimed;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'SSO connections'])] class extends Component
{
    public bool $creating = false;

    /** The Admin Portal setup URL, shown to the admin exactly once after minting. */
    public ?string $portalUrl = null;

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
        $this->guardEntitled();
        $this->authorizeAdmin();

        $connections->activate($this->orgId(), $id);
        session()->flash('status', 'Connection activated.');
    }

    /**
     * Mint a single-use Admin Portal link and reveal its URL once, so the admin
     * can hand SSO setup to an external IT admin without granting them an account.
     */
    public function invite(AdminPortal $portal): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();

        $token = $portal->generate($this->orgId(), 'sso', app(CurrentUser::class)->id());
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
            session()->flash('status', 'Domain verified.');

            return;
        }

        session()->flash('status', "We couldn't find the TXT record yet — DNS can take a few minutes.");
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
        session()->flash('status', $domain->capture ? 'Capture disabled.' : 'Capture enabled — matching users must use SSO.');
    }

    public function removeDomain(string $id, DomainVerification $domains): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();
        $this->ownedDomain($id, $domains);

        $domains->remove($id);
        session()->flash('status', 'Domain removed.');
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

    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            'entitled' => app(Entitlements::class)->entitled($this->orgId(), 'sso'),
            'connections' => Connection::query()
                ->where('organization_id', $this->orgId())
                ->orderByDesc('created_at')
                ->get(),
            'domains' => app(DomainVerification::class)->forOrganization($this->orgId()),
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

    /**
     * Deny-by-default entitlement gate for every mutating action. Runs BEFORE the
     * admin check, so a direct Livewire call from a non-entitled org is refused
     * even though the (upsell) screen itself is reachable.
     */
    private function guardEntitled(): void
    {
        abort_unless(app(Entitlements::class)->entitled($this->orgId(), 'sso'), 403);
    }
}; ?>

<div>
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Authentication</p>
            <h1 class="cbx-page-title">SSO connections</h1>
            <p class="cbx-page-desc">Federate sign-in with your enterprise identity provider.</p>
        </div>
        @if ($me->isAdmin() && $entitled)
            <div class="flex items-center gap-2">
                <button wire:click="invite" class="btn btn-ghost"><x-icon name="members" class="w-4 h-4" /> Invite your IT admin</button>
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New connection</button>
            </div>
        @endif
    </div>

    <div class="mt-8 space-y-6">
    @if (session('status'))
        <div class="card p-3 text-sm flex items-center gap-2" style="border-color:color-mix(in oklch, var(--success) 30%, transparent);background:var(--success-soft);color:var(--success)">
            <x-icon name="check" class="w-4 h-4" /> {{ session('status') }}
        </div>
    @endif

    @if (! $entitled)
        <div class="card">
            <div class="cbx-empty">
                <div class="cbx-empty-icon"><x-icon name="connections" class="w-5 h-5" /></div>
                <h3>Single sign-on is an Enterprise feature</h3>
                <p>
                    SAML &amp; OIDC single sign-on is available on the Enterprise plan.
                    Contact your account team to enable it for this organization.
                </p>
            </div>
        </div>
    @else

    @if ($portalUrl && $me->isAdmin())
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

    @if ($creating && $me->isAdmin())
        <form wire:submit="create" class="card p-5 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="name">Connection name</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="Acme Okta" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="type">Protocol</label>
                    <select wire:model.live="type" id="type" class="select">
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
                    @if ($me->isAdmin() && ! $c->isActive())
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
                <div class="cbx-empty">
                    <div class="cbx-empty-icon"><x-icon name="connections" class="w-5 h-5" /></div>
                    <h3>No SSO connections yet</h3>
                    <p>Connect an identity provider to let your team sign in with SAML or OIDC.</p>
                </div>
            </div>
        @endforelse
    </div>

    {{-- Verified domains — DNS-proven ownership powers home-realm discovery and the optional capture gate. --}}
    <div class="space-y-4">
        <div>
            <h2 class="cbx-panel-title" style="font-size:18px">Verified domains</h2>
            <p class="mt-1 text-sm" style="color:var(--muted-foreground)">Prove ownership of an email domain to route your team to SSO automatically.</p>
        </div>

        @if ($me->isAdmin())
            <form wire:submit="addDomain" class="card p-5 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[14rem]">
                    <label class="label" for="domain">Domain</label>
                    <input wire:model="domain" id="domain" type="text" inputmode="url" autocapitalize="none" spellcheck="false" class="input mono" placeholder="acme.com">
                    @error('domain') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="addDomain"><x-icon name="plus" class="w-4 h-4" /> Add domain</button>
            </form>
        @endif

        @if ($dnsHost && $dnsToken && $me->isAdmin())
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
                <div class="card p-5">
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
                        @if ($me->isAdmin())
                            <div class="flex items-center gap-2">
                                @unless ($d->verified_at)
                                    <button wire:click="verifyDomain('{{ $d->id }}')" class="btn btn-primary btn-sm"><x-icon name="check" class="w-4 h-4" /> Verify</button>
                                @endunless
                                <button wire:click="removeDomain('{{ $d->id }}')" wire:confirm="Remove {{ $d->domain }}?" class="btn btn-ghost btn-sm">Remove</button>
                            </div>
                        @endif
                    </div>

                    @if ($d->verified_at && $me->isAdmin())
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
                    <div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="directory" class="w-5 h-5" /></div>
                        <h3>No domains yet</h3>
                        <p>Add one to enable domain-based SSO routing.</p>
                    </div>
                </div>
            @endforelse
        </div>
    </div>
    @endif
    </div>
</div>
