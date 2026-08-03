<?php

declare(strict_types=1);

use App\Platform\AdminPortal;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Enums\PortalScope;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Exceptions\DomainAlreadyClaimed;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Console › Single sign-on (list) — one component, both planes. Federated SAML and OIDC
 * connections, each row deep-linking to the connection's own page where the config, the
 * enable/disable switch and deletion live.
 *
 * This was two pages. The organization plane had a single page that could create and
 * activate a connection, verify email domains, toggle the capture gate and hand SSO
 * setup to an external IT admin through the Admin Portal — and could not edit, disable
 * or delete a connection at all. The environment plane had a routable list → new →
 * detail that could do all three and had none of the domain or portal half. Both halves
 * are here now: the routable shape wins, because a connection URL is something you send
 * to whoever runs the identity provider, and everything the single page offered comes
 * with it.
 *
 * Domain verification stays on the list rather than moving to a connection's page: a
 * verified domain belongs to the ORGANIZATION, not to one connection — it is how a
 * person's email address finds whichever connection is active — so hanging it off a
 * connection would be modelling it wrong.
 *
 * Connections are environment-owned (BelongsToEnvironment), so the model's global scope
 * only ever resolves records inside this environment; the acting organization narrows it
 * further, which is what keeps a tenant admin out of another tenant's rows.
 */
new #[Layout('components.layouts.console', ['title' => 'Single sign-on'])] class extends Component
{
    use WithPagination;

    /**
     * Read gate re-checked on EVERY request, not just first mount: boot() runs on each
     * hydration, so an admin demoted mid-session cannot keep re-rendering org-wide SSO
     * config from a stale snapshot. This page exposes admin-only data, so the render
     * path itself is gated.
     */
    public function boot(): void
    {
        $this->authorizeAdmin();
    }

    #[Url(as: 'q')]
    public string $search = '';

    /** The Admin Portal setup URL, shown to the admin exactly once after minting. */
    public ?string $portalUrl = null;

    // Verified domains
    public string $domain = '';

    /** DNS instructions for the domain just added — shown once so the admin can publish the TXT record. */
    public ?string $dnsHost = null;

    public ?string $dnsToken = null;

    public ?string $dnsDomain = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Mint a single-use Admin Portal link and reveal its URL once, so the admin can hand
     * SSO setup to an external IT admin without granting them an account.
     */
    public function invite(AdminPortal $portal): void
    {
        $this->guardEntitled();
        $this->authorizeAdmin();

        $scope = app(ConsoleScope::class);

        // actorId(), not CurrentUser::id(): on the environment plane there is no subject
        // session to ask, and the one thing this link records is who minted it.
        $token = $portal->generate($scope->requireOrganizationId(), PortalScope::Sso, $scope->actorId());

        $this->portalUrl = route('portal.enter', $token);
    }

    /**
     * Register a domain for the acting organization and mint its DNS challenge. The
     * instructions (challenge host + token) are surfaced once so the admin can publish
     * the TXT record.
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
            $record = $domains->add(app(ConsoleScope::class)->requireOrganizationId(), $this->domain);
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
     * Re-check the DNS TXT record for a domain this organization owns and mark it
     * verified.
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
     * Toggle the capture gate on a VERIFIED domain this organization owns.
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
     * Resolve a domain the ACTING organization owns, or refuse.
     *
     * The contract is already org-scoped, but resolving through forOrganization() means a
     * foreign domain id simply never matches — closing cross-org id tampering
     * (deny-by-default). requireOrganizationId() rather than the nullable reader, because
     * an environment administrator who has chosen nothing must not thereby be handed
     * every domain in the environment by id.
     */
    private function ownedDomain(string $id, DomainVerification $domains): VerifiedDomain
    {
        foreach ($domains->forOrganization(app(ConsoleScope::class)->requireOrganizationId()) as $domain) {
            if ($domain->id === $id) {
                return $domain;
            }
        }

        abort(403);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $scope = app(ConsoleScope::class);
        $organizationId = $this->actingOrganizationId();

        // Scoped to the acting organization when one is chosen. With none chosen — only
        // possible for an environment administrator — this is every connection in the
        // environment, which is a deliberate overview rather than a leak: the model's
        // environment scope still bounds it, and an organization member can never reach
        // this branch because their organization is implicit.
        $query = Connection::query()
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where('organization_id', $organizationId))
            ->orderByDesc('created_at');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where('name', 'like', "%{$term}%");
        }

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            // The view's own authorization question, asked through the scope so it gets
            // the same answer on both planes. It used to read CurrentUser::isAdmin()
            // directly in ten places — a question only the organization plane can answer,
            // so on the environment plane every admin control silently disappeared and
            // the page rendered as an empty read-only shell.
            'mayAdminister' => $scope->mayAdminister(),
            // "No organization chosen" is a real state on the environment plane and must
            // not be reported as "not entitled" — entitled() answers false either way,
            // and telling an administrator to contact their account team when they simply
            // have not picked an organization sends them somewhere useless.
            'needsOrganization' => $organizationId === null,
            'entitled' => $scope->entitled('sso'),
            'connections' => $query->paginate(25),
            // The scope's own list, not a bare Organization query: on the organization
            // plane that is the member's one organization, so naming a connection's owner
            // can never enumerate the environment's other tenants.
            'orgNames' => $scope->availableOrganizations(),
            // A domain belongs to ONE organization, so the whole-environment overview has
            // none to show rather than every tenant's.
            'domains' => $organizationId === null
                ? []
                : app(DomainVerification::class)->forOrganization($organizationId),
        ];
    }

    /**
     * The organization whose connections this page is about, or null for the
     * whole-environment overview.
     *
     * Null has to mean "an environment administrator has not chosen yet" and never "this
     * member has no organization" — the nullable reader answers null for both, and on
     * the organization plane that second case would widen the list from one organization
     * to every organization in the environment.
     */
    private function actingOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Environment
            ? $scope->organizationId()
            : $scope->requireOrganizationId();
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
     * Deny-by-default entitlement gate for every mutating action. Runs BEFORE the admin
     * check, so a direct Livewire call from a non-entitled org is refused even though the
     * (upsell) screen itself is reachable.
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
                <a href="{{ route($scopeRoute('connections.create')) }}" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New connection</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if (! $needsOrganization && ! $entitled)
        <div class="card">
            <x-empty-state icon="connections" title="Single sign-on is an Enterprise feature"
                           :help="\App\Platform\Help\HelpTopic::SingleSignOn"
                           body="Letting your people sign in with Entra ID, Okta or Google Workspace is available on the Enterprise plan. Contact your account team to enable it for this organization." />
        </div>
    @else
        <div class="mt-8 space-y-6">

        @if ($needsOrganization)
            {{-- The environment administrator's overview: every connection in the
                 environment. Adding one, or proving a domain, belongs to a single
                 organization, so those wait until one is chosen. --}}
            <div class="card">
                <x-empty-state icon="layers" title="Choose an organization"
                               body="Below is every connection in this environment. To add one, verify a domain or invite an IT admin, pick the organization you are configuring — the selector sits in the bar at the top of the page." />
            </div>
        @endif

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

        <div>
            <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by name" aria-label="Search connections">
            {{-- SC 4.1.3: the list re-renders on a debounced keystroke with no focus
                 change, so the result count is the only thing that can report the filter
                 narrowed to nothing. --}}
            <p role="status" aria-live="polite" class="sr-only">{{ $connections->total() }} {{ \Illuminate\Support\Str::plural('connection', $connections->total()) }} found.</p>
        </div>

        <div class="rounded-xl border overflow-hidden" style="border-color:var(--border)">
            @forelse ($connections as $c)
                <a wire:key="connection-{{ $c->id }}" href="{{ route($scopeRoute('connections.show'), $c->id) }}"
                   class="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)] {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-medium truncate">{{ $c->name }}</span>
                            @if ($needsOrganization)
                                <span class="badge">{{ $orgNames[$c->organization_id] ?? $c->organization_id }}</span>
                            @endif
                        </div>
                        <p class="text-xs truncate mono" style="color:var(--faint)">{{ $c->id }}</p>
                    </div>
                    <span class="cbx-pill cbx-pill--info">{{ strtoupper($c->type->value) }}</span>
                    <span class="badge {{ $c->isActive() ? 'badge-success' : '' }}">{{ $c->isActive() ? 'Active' : ucfirst($c->status->value) }}</span>
                    <x-icon name="chevron" class="w-4 h-4 shrink-0" style="color:var(--faint)" />
                </a>
            @empty
                @if (trim($search) !== '')
                    <div class="cbx-empty">
                        <div class="cbx-empty-icon"><x-icon name="search" class="w-5 h-5" /></div>
                        <h3>No matches for "{{ trim($search) }}"</h3>
                        <p>No connections match that name. Try a different search term.</p>
                    </div>
                @else
                    {{-- The organization console's empty state, kept: an empty page is the
                         most-read explanation in the console, and "No connections yet"
                         tells someone who has not read the guide nothing at all. --}}
                    <x-empty-state icon="connections" title="No identity provider connected yet"
                                   :help="\App\Platform\Help\HelpTopic::SingleSignOn"
                                   body="Right now people sign in with credentials held here. Connect your provider and they use the company account they already have — and lose access here the moment you disable it there."
                                   :steps="[
                                       'In Entra ID, Okta or Google Workspace, create an application for Cbox ID.',
                                       'Add the connection — paste the provider’s metadata and the fields fill themselves.',
                                       'Verify the email domains you own, so your people are routed to your provider automatically.',
                                       'Activate the connection once a test sign-in works.',
                                   ]">
                        @if ($mayAdminister && $entitled)
                            <x-slot:actions>
                                <a href="{{ route($scopeRoute('connections.create')) }}" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New connection</a>
                                <button wire:click="invite" class="btn btn-ghost"><x-icon name="members" class="w-4 h-4" /> Hand it to your IT admin</button>
                            </x-slot:actions>
                        @endif
                    </x-empty-state>
                @endif
            @endforelse
        </div>

        <div>{{ $connections->links() }}</div>

        @unless ($needsOrganization)
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
                        <div wire:key="domain-{{ $d->id }}" class="card p-5">
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
        @endunless
        </div>
    @endif
</div>
