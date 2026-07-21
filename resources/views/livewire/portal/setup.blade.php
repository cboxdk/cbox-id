<?php

declare(strict_types=1);

use App\Platform\AdminPortal;
use App\Platform\Enums\PortalFeature;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\DomainAlreadyClaimed;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Organization\Contracts\Organizations;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The external IT admin's setup screen. It reads the bound org id and scope FROM
 * THE PORTAL SESSION ONLY — never from request input — so a redeemer can only
 * ever configure the one org their link was minted for. Every mutating action
 * re-checks the scoped session and feeds the session's org id to the org-scoped
 * package contracts.
 */
new #[Layout('components.layouts.portal', ['title' => 'Set up SSO & SCIM'])] class extends Component
{
    public bool $done = false;

    // Domain verification (step 1 of SSO onboarding).
    public string $domain = '';

    public ?string $dnsHost = null;

    public ?string $dnsToken = null;

    public ?string $dnsDomain = null;

    // SSO connection form.
    public bool $creatingConnection = false;

    public string $type = 'saml';

    public string $connName = '';

    public string $idp_entity_id = '';

    public string $idp_sso_url = '';

    public string $idp_x509cert = '';

    public string $sp_entity_id = '';

    public string $sp_acs_url = '';

    public string $issuer = '';

    public string $client_id = '';

    public string $signing_key = '';

    // SCIM directory form.
    public bool $creatingDirectory = false;

    public string $dirName = '';

    /** One-time SCIM bearer token, shown once right after registration. */
    public ?string $newToken = null;

    public ?string $newTokenName = null;

    public function addDomain(DomainVerification $domains): void
    {
        $orgId = $this->guardFeature(PortalFeature::Sso);

        $this->domain = strtolower(trim($this->domain));
        $this->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/'],
        ], ['domain.regex' => 'Enter a valid domain, e.g. acme.com.']);

        try {
            $record = $domains->add($orgId, $this->domain);
        } catch (DomainAlreadyClaimed) {
            $this->addError('domain', 'That domain is already claimed by another organization.');

            return;
        }

        // Surface the DNS TXT record the IT admin must publish to prove control.
        $this->dnsHost = $domains->challengeHost($record->domain);
        $this->dnsToken = $record->verification_token;
        $this->dnsDomain = $record->domain;
        $this->domain = '';
    }

    public function verifyDomain(string $id, DomainVerification $domains): void
    {
        $this->guardFeature(PortalFeature::Sso);
        $this->ownedDomain($id, $domains);

        // Two dispatches, not one with a ternary: `severity` applied to the whole
        // expression announced a SUCCESSFUL verification in red, assertively.
        if ($domains->verify($id)) {
            $this->dispatch('toast', message: 'Domain verified — users on this domain can now sign in with SSO.');
        } else {
            $this->dispatch('toast', message: "We couldn't find the TXT record yet — DNS can take a few minutes to propagate.", severity: 'error');
        }
    }

    public function removeDomain(string $id, DomainVerification $domains): void
    {
        $this->guardFeature(PortalFeature::Sso);
        $this->ownedDomain($id, $domains);

        $domains->remove($id);
        $this->dispatch('toast', message: 'Domain removed.');
    }

    /** Confirm the domain belongs to the portal-bound org before acting on it. */
    private function ownedDomain(string $id, DomainVerification $domains): VerifiedDomain
    {
        $orgId = $this->guardFeature(PortalFeature::Sso);

        foreach ($domains->forOrganization($orgId) as $domain) {
            if ($domain->id === $id) {
                return $domain;
            }
        }

        abort(403);
    }

    public function createConnection(Connections $connections): void
    {
        $orgId = $this->guardFeature(PortalFeature::Sso);

        $type = ConnectionType::from($this->validate(['type' => 'required|in:saml,oidc'])['type']);
        $this->validate(['connName' => 'required|string|max:120']);

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

        $connections->create($orgId, $type, $this->connName, $config);

        $this->reset(
            'creatingConnection', 'connName', 'idp_entity_id', 'idp_sso_url', 'idp_x509cert',
            'sp_entity_id', 'sp_acs_url', 'issuer', 'client_id', 'signing_key',
        );
        $this->type = 'saml';
        $this->dispatch('toast', message: 'Connection created as a draft.');
    }

    public function activate(string $id, Connections $connections): void
    {
        $orgId = $this->guardFeature(PortalFeature::Sso);

        $connections->activate($orgId, $id);
        $this->dispatch('toast', message: 'Connection activated.');
    }

    public function registerDirectory(Directories $directories): void
    {
        $orgId = $this->guardFeature(PortalFeature::Scim);

        $this->validate(['dirName' => 'required|string|max:120']);

        $registered = $directories->register($orgId, $this->dirName);

        $this->newToken = $registered->token;
        $this->newTokenName = $registered->directory->name;
        $this->reset('creatingDirectory', 'dirName');
    }

    public function dismissToken(): void
    {
        $this->reset('newToken', 'newTokenName');
    }

    public function finish(AdminPortal $portal): void
    {
        // Belt-and-suspenders with the middleware: only a live session may finish.
        abort_unless($portal->sessionValid(), 403);

        $portal->complete();
        $this->done = true;
    }

    public function with(): array
    {
        $portal = app(AdminPortal::class);
        $orgId = $portal->boundOrgId();

        return [
            'showSso' => $portal->canConfigure(PortalFeature::Sso),
            'showScim' => $portal->canConfigure(PortalFeature::Scim),
            'orgName' => $orgId === null ? null : app(Organizations::class)->find($orgId)?->name,
            'domains' => $orgId === null ? [] : app(DomainVerification::class)->forOrganization($orgId),
            'connections' => $orgId === null ? collect() : Connection::query()
                ->where('organization_id', $orgId)
                ->orderByDesc('created_at')
                ->get(),
            'directories' => $orgId === null ? collect() : Directory::query()
                ->where('organization_id', $orgId)
                ->orderByDesc('created_at')
                ->get(),
        ];
    }

    /**
     * Guard a mutating action: the portal session must be live AND permit the
     * feature. Returns the bound org id — the only org id any action ever acts on.
     */
    private function guardFeature(PortalFeature $feature): string
    {
        $portal = app(AdminPortal::class);

        abort_unless($portal->sessionValid() && $portal->canConfigure($feature), 403);

        $orgId = $portal->boundOrgId();
        abort_if($orgId === null, 403);

        return $orgId;
    }
}; ?>

<div>
    @if ($done)
        <div class="card p-10 text-center">
            <div class="mx-auto grid place-items-center rounded-full" style="width:2.75rem;height:2.75rem;background:var(--success-soft);color:var(--success)">
                <x-icon name="check" class="w-5 h-5" />
            </div>
            <h2 class="mt-4 text-lg font-semibold tracking-tight">All set</h2>
            <p class="mt-2 text-sm leading-relaxed mx-auto" style="color:var(--muted);max-width:28rem">
                Enterprise sign-in for {{ $orgName ?? 'this organization' }} is configured. This
                setup link has now been used and is closed. You can close this window.
            </p>
        </div>
    @else
        <x-page-header
            title="Set up enterprise sign-in{{ $orgName ? ' · '.$orgName : '' }}"
            subtitle="You were invited to configure single sign-on for this organization. Nothing else on the account is accessible from here." />

        @if (session('status'))
            <div class="card p-3 mb-5 text-sm flex items-center gap-2" style="border-color:color-mix(in srgb, var(--success) 30%, transparent);background:var(--success-soft);color:var(--success)">
                <x-icon name="check" class="w-4 h-4" /> {{ session('status') }}
            </div>
        @endif

        @if ($showSso)
            {{-- Step 1 — prove domain ownership. A verified domain is what routes your
                 users to this SSO connection, so it comes first. --}}
            <section class="mb-8">
                <div class="mb-3">
                    <p class="cbx-page-eyebrow">Step 1</p>
                    <h3 class="text-sm font-semibold flex items-center gap-2 mt-1"><x-icon name="shield" class="w-4 h-4" /> Verify your domain</h3>
                    <p class="text-xs mt-1" style="color:var(--muted)">Add a DNS record to prove you own the domain your team signs in with. This is what sends those users to SSO.</p>
                </div>

                <form wire:submit="addDomain" class="flex gap-2 mb-4">
                    <input wire:model="domain" type="text" class="input" placeholder="acme.com"
                           @error('domain') aria-invalid="true" @enderror>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Add domain</button>
                </form>
                @error('domain') <p class="field-error -mt-2 mb-4">{{ $message }}</p> @enderror

                @if ($dnsToken)
                    <div class="card p-4 mb-4" style="border-color:color-mix(in oklch, var(--warning) 40%, transparent)">
                        <p class="text-sm font-semibold">Add this TXT record for <span class="mono">{{ $dnsDomain }}</span>, then click Check.</p>
                        <div class="mt-3 grid gap-2 text-sm" style="grid-template-columns:auto 1fr">
                            <span class="text-xs" style="color:var(--muted)">Type</span><span class="mono">TXT</span>
                            <span class="text-xs" style="color:var(--muted)">Host</span><span class="mono break-all select-all">{{ $dnsHost }}</span>
                            <span class="text-xs" style="color:var(--muted)">Value</span><span class="mono break-all select-all">{{ $dnsToken }}</span>
                        </div>
                    </div>
                @endif

                @forelse ($domains as $d)
                    <div class="card p-3 mb-2 flex items-center justify-between gap-3">
                        <span class="mono text-sm">{{ $d->domain }}</span>
                        <div class="flex items-center gap-2">
                            @if ($d->isVerified())
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Verified</span>
                            @else
                                <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>Pending DNS</span>
                                <button wire:click="verifyDomain('{{ $d->id }}')" class="btn btn-ghost btn-sm">Check</button>
                            @endif
                            <button wire:click="removeDomain('{{ $d->id }}')" wire:confirm="Remove {{ $d->domain }}?" class="btn btn-ghost btn-sm" style="color:var(--danger)">Remove</button>
                        </div>
                    </div>
                @empty
                    <p class="text-xs" style="color:var(--faint)">No domains added yet.</p>
                @endforelse
            </section>

            <section class="mb-8">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <p class="cbx-page-eyebrow">Step 2</p>
                        <h3 class="text-sm font-semibold flex items-center gap-2 mt-1"><x-icon name="connections" class="w-4 h-4" /> SSO connection</h3>
                    </div>
                    <button wire:click="$toggle('creatingConnection')" class="btn btn-primary btn-sm"><x-icon name="plus" class="w-4 h-4" /> New connection</button>
                </div>

                @if ($creatingConnection)
                    <form wire:submit="createConnection" class="card p-5 mb-4 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label" for="connName">Connection name</label>
                                <input wire:model="connName" id="connName" type="text" class="input" placeholder="Acme Okta" autofocus>
                                @error('connName') <p class="field-error">{{ $message }}</p> @enderror
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
                            <button type="button" wire:click="$set('creatingConnection', false)" class="btn btn-ghost">Cancel</button>
                        </div>
                    </form>
                @endif

                <div class="space-y-3">
                    @forelse ($connections as $c)
                        <div class="card p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="font-semibold truncate">{{ $c->name }}</p>
                                        <span class="cbx-pill">{{ strtoupper($c->type->value) }}</span>
                                        @if ($c->isActive())
                                            <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Active</span>
                                        @else
                                            <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>{{ ucfirst($c->status->value) }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs mono truncate" style="color:var(--faint)">{{ $c->id }}</p>
                                </div>
                                @if (! $c->isActive())
                                    <button wire:click="activate('{{ $c->id }}')" class="btn btn-primary btn-sm"><x-icon name="check" class="w-4 h-4" /> Activate</button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm px-1" style="color:var(--faint)">No SSO connections yet.</p>
                    @endforelse
                </div>
            </section>
        @endif

        @if ($showScim)
            <section class="mb-8">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <p class="cbx-page-eyebrow">{{ $showSso ? 'Step 3' : 'Directory sync' }}</p>
                        <h3 class="text-sm font-semibold flex items-center gap-2 mt-1"><x-icon name="directory" class="w-4 h-4" /> Directory sync (SCIM)</h3>
                    </div>
                    <button wire:click="$toggle('creatingDirectory')" class="btn btn-primary btn-sm"><x-icon name="plus" class="w-4 h-4" /> New directory</button>
                </div>

                <div class="card p-4 mb-4">
                    <p class="text-xs" style="color:var(--muted)">Point your identity provider (Okta, Microsoft Entra) at this base URL and authenticate with a directory's bearer token.</p>
                    <p class="mt-2 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ url('/scim/v2') }}</p>
                </div>

                @if ($newToken)
                    <div class="card p-5 mb-4" style="border-color:color-mix(in srgb, var(--warn) 40%, transparent)">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 font-semibold"><x-icon name="key" class="w-4 h-4" /> Bearer token for “{{ $newTokenName }}”</div>
                                <p class="mt-1 text-sm" style="color:var(--warn)">Copy this now — it is shown only once and cannot be retrieved again.</p>
                            </div>
                            <button wire:click="dismissToken" class="btn btn-ghost btn-sm">Done</button>
                        </div>
                        <p class="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $newToken }}</p>
                    </div>
                @endif

                @if ($creatingDirectory)
                    <form wire:submit="registerDirectory" class="card p-4 mb-4 flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[14rem]">
                            <label class="label" for="dirName">Directory name</label>
                            <input wire:model="dirName" id="dirName" type="text" class="input" placeholder="Acme Okta SCIM" autofocus>
                            @error('dirName') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Register directory</button>
                        <button type="button" wire:click="$set('creatingDirectory', false)" class="btn btn-ghost">Cancel</button>
                    </form>
                @endif

                <div class="space-y-3">
                    @forelse ($directories as $dir)
                        <div class="card p-4 flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium truncate">{{ $dir->name }}</p>
                                <p class="text-xs mono truncate" style="color:var(--faint)">{{ $dir->id }}</p>
                            </div>
                            @if ($dir->status === \Cbox\Id\Directory\Enums\DirectoryStatus::Active)
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Active</span>
                            @else
                                <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>Paused</span>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm px-1" style="color:var(--faint)">No directories connected yet.</p>
                    @endforelse
                </div>
            </section>
        @endif

        <div class="flex items-center justify-end gap-2 border-t pt-5" style="border-color:var(--border)">
            <button wire:click="finish" class="btn btn-primary"><x-icon name="check" class="w-4 h-4" /> Finish setup</button>
        </div>
    @endif
</div>
