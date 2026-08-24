<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\AppKind;
use App\Platform\Connect\ConnectSnippets;
use App\Platform\ScopeCatalog;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use App\Rules\SecureRedirectUri;
use Cbox\Id\AccessControl\AppManifestPuller;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Console › Apps & API keys › detail. The full, deep-linkable lifecycle for one OAuth
 * client: the credentials to wire it up, editable name and redirect URIs, the roles
 * manifest it publishes, secret rotation and delete.
 *
 * One component, both planes, and each plane gained half of this page. An environment
 * administrator could not manage an app's manifest or sync it; an organization
 * administrator could not rotate a secret or edit an app's details, only create and
 * delete. Neither was decided — they were two pages written a year apart.
 *
 * Every read and every mutation re-resolves the app within THIS environment (the Client
 * model's BelongsToEnvironment scope) AND within what the acting scope may see, then 404s
 * otherwise. That second half is new and it is the point: the environment plane resolved
 * on the primary key alone, which was safe only while an environment administrator — who
 * holds every organization here — was the sole caller. Serving the same page to a tenant
 * admin would have handed them every other tenant's app by id, and on THIS page that
 * means rotating a live credential out from under someone else's production deployment,
 * or deleting the app it belongs to.
 *
 * The client secret is never stored in the clear: only its SHA-256 hash lives in the
 * database. It is shown exactly once — freshly on creation (handed over by flash) or when
 * rotated here — and can never be echoed again.
 */
new #[Layout('components.layouts.console', ['title' => 'App'])] class extends Component
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
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public string $clientId = '';

    public string $editName = '';

    public string $editRedirectUris = '';

    /** Where sign-out may send people back to. Validated exactly like redirect URIs. */
    public string $editPostLogoutRedirectUris = '';

    /** Where this app publishes its role/permission manifest for Cbox ID to pull. */
    public string $editManifestUrl = '';

    /**
     * The scopes this app is registered for — EDITABLE, which they were not.
     *
     * The page showed them as read-only badges, so the only way to add one to a live app
     * was to delete it and register a new one, taking its client id and secret with it.
     * Every integration holding those credentials breaks, to add a string to a list.
     *
     * @var array<int, string>
     */
    public array $editScopes = [];

    /** Advanced: extra scope keys the catalog does not offer, comma-separated. */
    public string $editCustomScopes = '';

    /** Whether the manifest panel is expanded. */
    public bool $managingManifest = false;

    /**
     * Plaintext secret, held only for the render that reveals it.
     *
     * Protected, so Livewire never dehydrates it into the wire:snapshot embedded in the
     * DOM — the plaintext lives only in the response that reveals it, and is gone on the
     * next rehydration whether or not anyone dismisses the banner.
     */
    /**
     * Where a rotation interrupted by a step-up is remembered, so it can be finished
     * rather than silently dropped. Holds the client id it was asked for.
     */
    private const RESUME_KEY = 'clients.rotate_after_step_up';

    protected ?string $revealedSecret = null;

    /** Whether the revealed secret was just minted here, rather than handed over at creation. */
    public bool $revealedIsFresh = false;

    public function mount(string $client): void
    {
        $this->clientId = $client;

        // Resolves through the same visibility rule every later read uses, so an id the
        // acting scope may not see 404s here rather than at the first action.
        $model = $this->client();

        $this->editName = $model->name;
        $this->editRedirectUris = implode("\n", $model->redirect_uris ?? []);
        $this->editPostLogoutRedirectUris = implode("\n", $model->post_logout_redirect_uris ?? []);
        $this->editManifestUrl = $model->manifest_url ?? '';

        $catalogKeys = app(ScopeCatalog::class)->keys();
        $registered = array_values($model->scopes ?? []);

        // Split by whether the catalog knows the key, so a scope somebody typed by hand
        // survives an edit instead of being dropped the first time this form is saved.
        $this->editScopes = array_values(array_intersect($registered, $catalogKeys));
        $this->editCustomScopes = implode(', ', array_values(array_diff($registered, $catalogKeys)));

        // A secret handed over by the create page — shown once, then aged out. Read in
        // mount() rather than in with(), because the flash is aged out at the end of this
        // request: read per-render it would vanish on the first unrelated action instead
        // of when the administrator says so.
        $flashed = session('revealed_secret');
        if (is_string($flashed) && $flashed !== '') {
            $this->revealedSecret = $flashed;
        }

        // FINISH WHAT THE STEP-UP INTERRUPTED. Pulled, so it is spent whether or not the
        // rotation below goes through — a flag that survived a refusal would fire on some
        // later visit to a page the person had no rotation in mind for.
        $resume = session()->pull(self::RESUME_KEY);

        if ($resume === $this->clientId) {
            // Straight back through the same method: it re-resolves the app, re-checks
            // that this administrator may manage it, and re-asks for the step-up. If the
            // window closed on the way here, they are simply sent to it again rather than
            // rotating on a stale confirmation.
            $this->rotateSecret();
        }
    }

    /**
     * The app, re-resolved and re-scoped on every read and write.
     *
     * An organization sees its own apps and the platform's first-party ones — those
     * appear in its launcher and skip its consent screen, so it must be able to read
     * them — and nothing else. 404 rather than 403, because the caller was not entitled
     * to learn the app exists.
     */
    private function client(): Client
    {
        $organizationId = $this->actingOrganizationId();

        $client = Client::query()
            ->whereKey($this->clientId)
            ->when($organizationId !== null, fn (Builder $q): Builder => $q->where(
                fn (Builder $scoped): Builder => $scoped
                    ->where('organization_id', $organizationId)
                    ->orWhere(fn (Builder $platform): Builder => $platform->whereNull('organization_id')->where('first_party', true))
            ))
            ->first();

        abort_if($client === null, 404);

        return $client;
    }

    /**
     * The app, refused unless this administrator may CHANGE it.
     *
     * Resolved inside the gate rather than checked afterwards, so every mutation below
     * shares one answer instead of each remembering to ask.
     */
    private function manageable(): Client
    {
        $client = $this->client();

        abort_unless($this->mayManage($client), 403);

        return $client;
    }

    /**
     * Whether this administrator may change this app, as opposed to look at it.
     *
     * On the ENVIRONMENT plane the administrator is the operator above every organization
     * in it, so every app this console can see is theirs to manage; EnvironmentScope is
     * the real boundary and an app in another environment is not visible here at all. On
     * the ORGANIZATION plane it is the organization's own apps and nothing else — which
     * is what makes the platform's first-party apps visible to a tenant admin and not
     * rotatable or deletable by one.
     */
    private function mayManage(Client $client): bool
    {
        $scope = app(ConsoleScope::class);

        if ($scope->plane() === ConsolePlane::Environment) {
            return true;
        }

        return $client->organization_id !== null
            && $client->organization_id === $scope->organizationId();
    }

    /**
     * The organization whose apps this page may resolve, or null for the whole
     * environment.
     *
     * Null has to mean "an environment administrator has not chosen one yet" and never
     * "this member has no organization" — the nullable reader answers null for both, and
     * on the organization plane that second case would resolve ANY app in the environment
     * by id, which on this page is a secret to rotate and an application to delete.
     */
    private function actingOrganizationId(): ?string
    {
        $scope = app(ConsoleScope::class);

        return $scope->plane() === ConsolePlane::Environment
            ? $scope->organizationId()
            : $scope->requireOrganizationId();
    }

    public function saveDetails(): void
    {
        $client = $this->manageable();

        $this->validate([
            'editName' => ['required', 'string', 'max:190'],
            'editRedirectUris' => ['nullable', 'string', 'max:2000'],
            'editPostLogoutRedirectUris' => ['nullable', 'string', 'max:2000'],
            'editCustomScopes' => ['nullable', 'string', 'max:500'],
        ]);

        $redirects = $this->splitLines($this->editRedirectUris);

        foreach ($redirects as $uri) {
            if (! SecureRedirectUri::isSecure($uri)) {
                $this->addError('editRedirectUris', 'Each redirect URI must use https (http is allowed only on localhost) — e.g. https://app.example.com/callback.');

                return;
            }
        }

        $postLogoutRedirects = $this->splitLines($this->editPostLogoutRedirectUris);

        // Held to the same bar as the sign-in redirect URIs: sign-out hands the
        // browser to this address, so it must not be a cleartext public URL.
        foreach ($postLogoutRedirects as $uri) {
            if (! SecureRedirectUri::isSecure($uri)) {
                $this->addError('editPostLogoutRedirectUris', 'Each sign-out URI must use https (http is allowed only on localhost) — e.g. https://app.example.com/signed-out.');

                return;
            }
        }

        $catalog = app(ScopeCatalog::class);

        $custom = array_values(array_filter(array_map(
            'trim',
            explode(',', $this->editCustomScopes),
        ), static fn (string $scope): bool => $scope !== ''));

        $scopes = array_values(array_unique(array_merge(
            array_values(array_intersect($this->editScopes, $catalog->keys())),
            $custom,
        )));

        $client->name = trim($this->editName);
        $client->redirect_uris = $redirects;
        $client->post_logout_redirect_uris = $postLogoutRedirects;
        // THE CEILING, and narrowing it takes effect on the next token. A device or CIBA
        // request naming a scope removed here is refused outright rather than downscoped,
        // so this is a live change to what an integration can ask for — which is exactly
        // why it belongs on the page rather than behind delete-and-recreate.
        $client->scopes = $scopes;
        $client->save();

        $this->dispatch('toast', message: 'App updated.');
    }

    /**
     * Expand or collapse the roles manifest panel.
     *
     * The organization console had this and the environment console had no manifest UI at
     * all — an environment administrator could neither point an app at its manifest nor
     * sync it, on the plane that holds every app in the environment. Collapsed by default
     * because most apps never publish one, and re-seeded from the record on each open so
     * the field cannot show a stale edit somebody abandoned.
     */
    public function openManifest(): void
    {
        $client = $this->manageable();

        $this->managingManifest = ! $this->managingManifest;

        if ($this->managingManifest) {
            $this->editManifestUrl = $client->manifest_url ?? '';
        }
    }

    public function saveManifestUrl(AppManifestPuller $puller): void
    {
        $client = $this->manageable();

        $this->validate(['editManifestUrl' => ['nullable', 'url', 'max:500']]);

        $client->forceFill(['manifest_url' => trim($this->editManifestUrl) ?: null])->save();

        if ($client->manifest_url === null) {
            $this->dispatch('toast', message: 'Manifest URL cleared.');

            return;
        }

        // Pull immediately so the app's roles appear without waiting for the sweep.
        try {
            $result = $puller->pull($client->refresh());
            $this->dispatch('toast', message: $result !== null ? 'Manifest synced — '.$result->rolesDeclared.' role(s), '.$result->permissionsDeclared.' permission(s).' : 'Saved.');
        } catch (Throwable $e) {
            $this->addError('editManifestUrl', 'Saved, but the sync failed: '.$e->getMessage());
        }
    }

    public function syncNow(AppManifestPuller $puller): void
    {
        $client = $this->manageable();

        if ($client->manifest_url === null) {
            return;
        }

        try {
            $result = $puller->pull($client);
            $this->dispatch('toast', message: $result !== null && ! $result->unchanged
                ? 'Synced — '.$result->rolesDeclared.' role(s).'
                : 'Already up to date.');
        } catch (Throwable $e) {
            $this->dispatch('toast', message: 'Sync failed: '.$e->getMessage(), severity: 'error');
        }
    }

    /**
     * Overlap-rotate the secret: mint a fresh one, persist only its hash, and reveal the
     * plaintext once. Public clients have no secret, so rotation is refused for them.
     *
     * BEHIND A STEP-UP. This mints a live credential for an app that already exists, and
     * on the environment plane {@see mayManage()} returns an unconditional true — so one
     * unattended env-admin session rotates any tenant's production app secret and puts the
     * plaintext on screen, with no re-authentication anywhere in the path. That is the
     * exact scenario {@see \App\Platform\EnvironmentSudo} was created for; it was applied
     * to the token vault and to nothing else, while this reached the same class of secret
     * through a page with no gate at all. The account plane has always demanded a fresh
     * password for the equivalent, which was the argument for creating the gate.
     *
     * On the ACTION rather than the route: this page is also where an app's name and
     * redirect URIs are read, and a password prompt to read a redirect URI is a gate
     * people learn to route around.
     */
    public function rotateSecret(): void
    {
        $client = $this->manageable();

        if ($client->type !== ClientType::Confidential) {
            $this->dispatch('toast', message: 'Public apps use PKCE and have no secret to rotate.');

            return;
        }

        // A private_key_jwt client is Confidential AND has no secret — deliberately, and
        // by construction: ClientRegistryService mints one only when no JWKS is
        // registered, because a client authenticates EITHER by a shared secret OR by
        // signing assertions, never both. Minting one here does not rotate anything; it
        // ADDS a bearer credential to a client that was asymmetric-only, and
        // ClientAuthenticator then accepts client_id + secret whenever no assertion is
        // presented. A one-click downgrade of an authentication model, offered as routine
        // hygiene.
        if ($client->jwks !== null) {
            $this->dispatch('toast', message: 'This app signs assertions with its own keys and has no secret. Rotate the key in its JWKS instead.', severity: 'error');

            return;
        }

        // LAST, after authorization and after the two refusals above. Asking for a
        // password and then answering "public apps have no secret to rotate" trains people
        // to type it without reading, and a step-up in front of a 403 would hand somebody
        // who may not touch this app a prompt instead of a refusal.
        $sudo = app(ConsoleStepUp::class)->challenge(
            'clients.show',
            'environment.clients.show',
            ['client' => $this->clientId],
            'Rotating this app\'s secret issues a new one and stops the old one working immediately.',
        );

        if ($sudo !== null) {
            // REMEMBER WHAT THEY ASKED FOR. Without this the step-up returns them to this
            // page with nothing done: they typed the app's name, clicked Rotate, entered
            // their password, and landed back on an unchanged page with no rotation and
            // nothing saying why. The only way to find out it had not happened was to do
            // the whole thing again — which is what people did, so the second attempt is
            // the one that worked and the first looked like a bug in the product.
            //
            // Single-use and keyed to THIS app: it is pulled on the way back in, so a
            // stale flag cannot rotate something later, and a flag naming another app is
            // ignored rather than applied to whatever page happens to open next.
            session()->put(self::RESUME_KEY, $this->clientId);

            $this->redirectRoute($sudo, navigate: false);

            return;
        }

        $secret = 'csec_'.bin2hex(random_bytes(32));
        $client->secret_hash = hash('sha256', $secret);
        $client->save();

        $this->revealedSecret = $secret;
        $this->revealedIsFresh = true;

        $this->dispatch('toast', message: 'A new secret was issued — copy it now, it will not be shown again.');
    }

    /**
     * Clear the revealed secret from the screen.
     *
     * The banner shows a live credential in plaintext, and an administrator who has copied
     * it — or who is sharing a screen — needs it gone; leaving it up until the next
     * navigation is not an answer. By the time this runs the plaintext is already off the
     * server, so making the banner go is the whole job.
     */
    public function dismissSecret(): void
    {
        $this->reset('revealedIsFresh');
        $this->revealedSecret = null;
    }

    /**
     * Delete the app.
     *
     * One capability that had two names — `delete` on the organization plane and
     * `deleteClient` on the environment plane — which is how a test exercising one plane
     * can pass while the other has been broken for a month.
     */
    public function delete(): mixed
    {
        $this->manageable()->delete();

        $this->dispatch('toast', message: 'App deleted.');

        return $this->redirectRoute(app(ConsoleScope::class)->routeName('clients'), navigate: true);
    }

    /** @return list<string> */
    private function splitLines(string $value): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n]+/', $value) ?: [],
        ), fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $client = $this->client();

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'client' => $client,
            // THE ENVIRONMENT'S OWN ISSUER, not the deployment's URL.
            //
            // This read `config('app.url')`, which is the apex the app is installed at —
            // so an app registered in a tenant environment on its own subdomain was told
            // its issuer was `https://cboxid.com`, from a page served at
            // `https://cbox.cboxid.com`. Every developer copying that value into an SDK
            // configured it against a host that serves no discovery document at all, and
            // the failure arrives one layer down as "discovery request failed" — pointing
            // at the SDK rather than at the console that handed them the wrong value.
            //
            // {@see IssuerResolver} is what /.well-known/openid-configuration and the
            // `iss` claim are both derived from, so this cannot disagree with what a
            // token actually says.
            'issuer' => rtrim(app(IssuerResolver::class)->issuer(), '/'),
            // Read back from the grants rather than stored: they are what the token
            // endpoint enforces, so they cannot disagree with what this page claims.
            'appKind' => $kind = AppKind::forClient($client),
            // One per SDK that can do this app's job, filled in with its real values.
            'snippets' => app(ConnectSnippets::class)->for($kind, $client, rtrim(app(IssuerResolver::class)->issuer(), '/')),
            'scopeGroups' => app(ScopeCatalog::class)->grouped(),
            // Protected → never dehydrated; passed explicitly so the secret renders once.
            'revealedSecret' => $this->revealedSecret,
            // How many roles this app currently declares. Tombstoned ones (orphaned_at)
            // are excluded: they are what the app STOPPED declaring.
            'declaredRoles' => Role::query()
                ->where('client_id', $client->client_id)
                ->whereNull('orphaned_at')
                ->count(),
            // A tenant admin sees the platform's first-party apps because they appear in
            // their launcher, but may not touch them — so the controls are not offered,
            // rather than offered and refused.
            'mayManage' => $this->mayManage($client),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route($scopeRoute('clients')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Apps & API keys</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $client->name }}</h1>
            @if ($client->first_party)
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent-strong)">First-party</span>
            @endif
            <span class="badge">{{ ucfirst($client->type->value) }}</span>
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $client->id }}</p>
    </div>

    {{-- One-time secret reveal (creation or rotation). Only the hash is stored, so this
         is the single moment the plaintext exists — it can never be echoed again. The
         organization console's "connect it" card comes with it: the issuer, the client id
         and a runnable SDK snippet, which is what someone actually needs in the one
         minute the secret is on screen. --}}
    @if ($revealedSecret)
        {{-- IT BRINGS ITSELF INTO VIEW. Rotation is triggered from the bottom of the page
             and reveals the new secret at the TOP of it, so the only feedback in the
             viewport was a toast in the opposite corner — and the person was left to
             guess that the thing they asked for had happened somewhere they could not
             see. `tabindex="-1"` with focus() rather than scroll alone, so a screen
             reader lands on the heading too. --}}
        <div x-data x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'start' }); $el.focus({ preventScroll: true })"
             tabindex="-1"
             class="rounded-xl border p-5" style="border-color:color-mix(in oklch, var(--warning) 40%, transparent);background:var(--warning-soft)">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold" style="color:var(--warning-strong)">{{ $revealedIsFresh ? 'New client secret' : 'Your app is ready — connect it' }}</p>
                    <p class="mt-1 text-xs" style="color:var(--muted)">Copy the secret now — only a hash is stored, so it won't be shown again. If you lose it, rotate to issue a new one.</p>
                </div>
                <button type="button" wire:click="dismissSecret" class="btn btn-ghost btn-sm shrink-0">Done</button>
            </div>

            {{-- A COPY BUTTON ON EVERY VALUE. Each of these is going into a config file
                 or an environment variable, and one of them is shown exactly once — so
                 "select it carefully with the mouse" is the wrong ask, and a mis-selected
                 secret is unrecoverable rather than merely annoying. --}}
            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg p-3" style="background:var(--surface-2);border:1px solid var(--border)">
                    <dt class="text-xs" style="color:var(--muted)">Issuer</dt>
                    <div class="mt-1 flex items-start justify-between gap-2">
                        <dd class="mono text-sm break-all select-all">{{ $issuer }}</dd>
                        <x-copy-button :value="$issuer" label="Copy" class="btn-ghost" />
                    </div>
                </div>
                <div class="rounded-lg p-3" style="background:var(--surface-2);border:1px solid var(--border)">
                    <dt class="text-xs" style="color:var(--muted)">Client ID</dt>
                    <div class="mt-1 flex items-start justify-between gap-2">
                        <dd class="mono text-sm break-all select-all">{{ $client->client_id }}</dd>
                        <x-copy-button :value="$client->client_id" label="Copy" class="btn-ghost" />
                    </div>
                </div>
                <div class="rounded-lg p-3 sm:col-span-2" style="background:var(--surface-2);border:1px solid var(--border)">
                    <dt class="text-xs font-semibold" style="color:var(--warning-strong)">Client secret — copy it now, it won't be shown again</dt>
                    <div class="mt-1 flex items-start justify-between gap-2">
                        <dd class="mono text-sm break-all select-all">{{ $revealedSecret }}</dd>
                        <x-copy-button :value="$revealedSecret" label="Copy secret" class="btn-primary" />
                    </div>
                </div>
            </dl>

        </div>
    @endif

    {{-- ALWAYS, NOT ONLY IN THE MINUTE AFTER CREATION. This lived inside the
         reveal card, which renders only when a plaintext secret was just flashed — so a
         public client never saw it at all, and that is precisely the CLI and the SPA:
         the two kinds whose author has no existing snippet to adapt. Coming back to the
         page a day later showed nothing either. --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Connect it</h2>
        <p class="mt-1 text-sm" style="color:var(--muted)">
            {{ $appKind->label() }} — {{ $appKind->description() }}
        </p>

        {{-- TABS, because the page used to show ONE example in JavaScript whatever the
             reader was building — and this screen is also where a one-time secret sits,
             so translating a JS snippet into Go happens under time pressure. Which SDKs
             appear follows from the app's kind: a device-flow tab under a service app is
             a tab that cannot work. --}}
        <div x-data="{ tab: @js($snippets[0]->id ?? 'curl') }" class="mt-4">
            <div class="flex flex-wrap gap-1" role="tablist" aria-label="SDK examples">
                @foreach ($snippets as $snippet)
                    <button type="button" role="tab"
                            wire:key="tab-{{ $snippet->id }}"
                            x-on:click="tab = @js($snippet->id)"
                            x-bind:aria-selected="tab === @js($snippet->id) ? 'true' : 'false'"
                            x-bind:class="tab === @js($snippet->id) ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-ghost'"
                            class="btn btn-sm btn-ghost">{{ $snippet->label }}</button>
                @endforeach
            </div>

            @foreach ($snippets as $snippet)
                <div x-show="tab === @js($snippet->id)" x-cloak wire:key="panel-{{ $snippet->id }}" role="tabpanel" class="mt-3">
                    @if ($snippet->install)
                        <div class="flex items-center gap-2">
                            <p class="mono text-xs rounded-lg px-3 py-2 flex-1 select-all" style="background:var(--surface-2);border:1px solid var(--border);color:var(--muted)">{{ $snippet->install }}</p>
                            <x-copy-button :value="$snippet->install" label="Copy" class="btn-ghost" />
                        </div>
                    @endif

                    <div class="mt-2 flex items-start gap-2">
                        <pre class="rounded-lg p-3 overflow-x-auto text-xs mono flex-1" style="background:var(--surface-2);border:1px solid var(--border);line-height:1.6">{{ $snippet->code }}</pre>
                        <x-copy-button :value="$snippet->code" label="Copy" class="btn-ghost" />
                    </div>

                    @if ($snippet->docs)
                        {{-- target=_blank: this is a reference opened WHILE wiring an app
                             up, and on the one screen that shows a secret exactly once.
                             Navigating away from it is how the secret is lost. --}}
                        <p class="mt-2 text-xs">
                            <a href="{{ $snippet->docs }}" target="_blank" rel="noopener noreferrer"
                               class="underline" style="color:var(--accent-strong)">{{ $snippet->label }} SDK reference ↗</a>
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Identifiers --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Credentials</h2>
        <div class="mt-4 space-y-3">
            <div>
                <p class="label">Issuer</p>
                <div class="flex items-center gap-2">
                    <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all flex-1" style="background:var(--surface-2);border:1px solid var(--border)">{{ $issuer }}</p>
                    <x-copy-button :value="$issuer" label="Copy" class="btn-ghost" />
                </div>
                <p class="mt-1 text-xs" style="color:var(--faint)">This environment's own issuer — what an SDK discovers from and what the <code class="mono">iss</code> claim carries.</p>
            </div>
            <div>
                <p class="label">Client ID</p>
                <div class="flex items-center gap-2">
                    <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all flex-1" style="background:var(--surface-2);border:1px solid var(--border)">{{ $client->client_id }}</p>
                    <x-copy-button :value="$client->client_id" label="Copy" class="btn-ghost" />
                </div>
            </div>
            <div>
                <p class="label">Client secret</p>
                @if ($client->type === ClientType::Confidential)
                    <p class="text-sm" style="color:var(--muted)">Stored as a hash and shown only once. Rotate to issue a new one.</p>
                @else
                    <p class="text-sm" style="color:var(--muted)">None — this is a public app and uses PKCE instead of a secret.</p>
                @endif
            </div>
        </div>
    </div>

    @if (! $mayManage)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <p class="text-sm" style="color:var(--muted)">This app belongs to the platform and is available to every organization in this environment. Your operator manages it.</p>
        </div>
    @endif

    {{-- Details --}}
    @if ($mayManage)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Details</h2>
            <form wire:submit="saveDetails" class="mt-4 space-y-4">
                <div>
                    <label class="label" for="editName">Name</label>
                    <input wire:model="editName" id="editName" type="text" class="input">
                    @error('editName') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="editRedirectUris">Redirect URIs <span style="color:var(--faint);font-weight:400">— one per line</span></label>
                    <textarea wire:model="editRedirectUris" id="editRedirectUris" rows="3" class="input mono" style="height:auto;padding:8px 10px;font-size:0.78rem" placeholder="https://app.example.com/auth/callback"></textarea>
                    @error('editRedirectUris') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="editPostLogoutRedirectUris">Sign-out URIs <span style="color:var(--faint);font-weight:400">— one per line</span></label>
                    <textarea wire:model="editPostLogoutRedirectUris" id="editPostLogoutRedirectUris" rows="3" class="input mono" style="height:auto;padding:8px 10px;font-size:0.78rem" placeholder="https://app.example.com/signed-out"></textarea>
                    <p class="mt-1 text-xs" style="color:var(--muted)">Where Cbox ID sends people after they sign out of this app. The URI the app asks for has to appear here character for character — trailing slash and all — or Cbox ID leaves the person on its own signed-out page. Leave empty if the app never sends people back.</p>
                    @error('editPostLogoutRedirectUris') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveDetails">Save changes</button>
            </form>
        </div>
    @endif

    {{-- Connection & permissions. The scopes were BADGES: to add one to a live app you
         deleted it and registered a new one, taking its client id and secret — and every
         integration holding them — with it, to add a string to a list. --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Connection &amp; permissions</h2>

        <div class="mt-4">
            <p class="label">Connects via</p>
            <p class="text-sm" style="color:var(--muted)">
                {{ $appKind->label() }} — {{ implode(', ', $client->grant_types ?? []) ?: '—' }}
            </p>
            <p class="mt-1 text-xs" style="color:var(--faint)">
                How an app connects is fixed at registration: changing it changes what the
                credentials mean, so register a new app rather than repurposing this one.
            </p>
        </div>

        @if ($mayManage)
            <form wire:submit="saveDetails" class="mt-5">
                <span class="label">What this app may ask for</span>
                <p class="mt-1 text-xs" style="color:var(--muted)">
                    Scopes — the ceiling on this app's access. Narrowing it takes effect on
                    the next token, and a device or agent request naming a scope you remove
                    is refused rather than quietly given less.
                </p>

                <div class="mt-3 space-y-4">
                    @foreach ($scopeGroups as $group => $groupScopes)
                        <div wire:key="editscope-{{ $group }}">
                            <p class="text-xs font-semibold uppercase mb-2" style="color:var(--muted);letter-spacing:0.05em">{{ $group }}</p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($groupScopes as $catalogScope)
                                    <label class="flex items-start gap-2.5 rounded-lg p-2.5 cursor-pointer" style="border:1px solid var(--border)">
                                        <input wire:model="editScopes" type="checkbox" value="{{ $catalogScope['key'] }}" class="mt-0.5 rounded">
                                        <span class="min-w-0">
                                            <span class="flex items-center gap-2 flex-wrap">
                                                <span class="text-sm font-medium">{{ $catalogScope['label'] }}</span>
                                                <span class="text-xs rounded-full px-2 py-0.5 mono" style="background:var(--surface-2);color:var(--muted)">{{ $catalogScope['key'] }}</span>
                                            </span>
                                            <span class="block text-xs mt-0.5" style="color:var(--muted)">{{ $catalogScope['description'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="mt-4">
                    <label class="label" for="editCustomScopes" style="font-weight:400;font-size:0.75rem">Advanced — custom scopes <span style="color:var(--faint)">(comma-separated)</span></label>
                    <input wire:model="editCustomScopes" id="editCustomScopes" type="text" class="input mono" placeholder="api.read, tax.data">
                    @error('editCustomScopes') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs" style="color:var(--faint)">Keys your own APIs check for. Kept as typed — a scope the catalog does not know survives an edit here.</p>
                </div>

                <button type="submit" class="btn btn-primary mt-4" wire:loading.attr="disabled" wire:target="saveDetails">Save scopes</button>
            </form>
        @else
            <div class="mt-4">
                <p class="label">Scopes</p>
                @if (count($client->scopes ?? []) > 0)
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($client->scopes as $clientScope)
                            <span class="badge mono">{{ $clientScope }}</span>
                        @endforeach
                    </div>
                @else
                    <span class="text-sm" style="color:var(--faint)">—</span>
                @endif
            </div>
        @endif
    </div>

    {{-- Roles & permissions the app declares (the manifest pull transport) --}}
    @if ($mayManage)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="cbx-section-title">Roles &amp; permissions</h2>
                    <p class="mt-1 text-sm" style="color:var(--muted)">The app declares these — Cbox ID pulls them from its manifest URL, or the app pushes them. They become assignable once they arrive.</p>
                </div>
                <button type="button" wire:click="openManifest" class="btn btn-ghost btn-sm shrink-0" aria-expanded="{{ $managingManifest ? 'true' : 'false' }}">
                    Manifest{{ $declaredRoles > 0 ? ' · '.$declaredRoles : '' }}
                </button>
            </div>

            @if ($managingManifest)
                <form wire:submit="saveManifestUrl" class="mt-4 flex flex-wrap items-end gap-2">
                    <div class="flex-1 min-w-[18rem]">
                        <label class="label" for="editManifestUrl">Manifest URL</label>
                        <input wire:model="editManifestUrl" id="editManifestUrl" type="url" class="input mono" placeholder="https://app.example.com/.well-known/cbox-authz" @error('editManifestUrl') aria-invalid="true" aria-describedby="editManifestUrl-error" @enderror>
                        @error('editManifestUrl') <p id="editManifestUrl-error" class="field-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveManifestUrl">Save &amp; sync</button>
                    @if ($client->manifest_url)
                        <button type="button" wire:click="syncNow" class="btn btn-ghost btn-sm" wire:loading.attr="disabled" wire:target="syncNow">Sync now</button>
                    @endif
                </form>
            @endif

            <p class="mt-3 text-xs" style="color:var(--muted)">
                @if ($declaredRoles > 0)
                    {{ $declaredRoles }} role(s) declared. See them on <a href="{{ route($scopeRoute('roles')) }}" class="underline" style="color:var(--accent-strong)">Roles</a>.
                @else
                    No roles declared yet — set a manifest URL and sync, or have the app push its manifest.
                @endif
            </p>
        </div>
    @endif

    {{-- Secret rotation --}}
    {{-- Not shown for an app that authenticates with its own keys: there is no secret
         to rotate, and the copy below ("the current one stops working") would be false —
         the button would CREATE a credential rather than replace one. --}}
    @if ($mayManage && $client->type === ClientType::Confidential && $client->jwks === null)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Rotate secret</h2>
            <p class="mt-1 text-sm" style="color:var(--muted)">Issue a fresh client secret. The current one stops working — update the app before rotating.</p>
            <div class="mt-4">
                <x-confirm-delete
                    :name="$editName"
                    action="rotateSecret"
                    label="Rotate secret"
                    verb="Rotate"
                    trigger-class="btn btn-ghost btn-sm"
                    consequence="The current client secret stops working immediately and cannot be recovered — every deployment still holding it starts failing authentication." />
            </div>
        </div>
    @endif

    {{-- Danger zone --}}
    @if ($mayManage)
        <div class="rounded-xl border p-5" style="border-color:var(--border)">
            <h2 class="cbx-section-title">Delete app</h2>
            <p class="mt-1 text-sm" style="color:var(--muted)">Anything using its credentials will stop working. This cannot be undone.</p>
            {{-- Irreversible AND felt outside this console: every integration using these
                 credentials stops working. A native confirm named neither the app nor the
                 environment, and Enter dismissed it. --}}
            <div class="mt-4">
                <x-confirm-delete
                    :name="$editName"
                    action="delete"
                    label="Delete app"
                    consequence="Anything using this app's credentials will stop working immediately. This cannot be undone." />
            </div>
        </div>
    @endif
</div>
