<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\AppKind;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use App\Rules\SecureRedirectUri;
use Cbox\Id\AccessControl\AppManifestPuller;
use Cbox\Id\AccessControl\Models\Role;
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

    /** Whether the manifest panel is expanded. */
    public bool $managingManifest = false;

    /**
     * Plaintext secret, held only for the render that reveals it.
     *
     * Protected, so Livewire never dehydrates it into the wire:snapshot embedded in the
     * DOM — the plaintext lives only in the response that reveals it, and is gone on the
     * next rehydration whether or not anyone dismisses the banner.
     */
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

        // A secret handed over by the create page — shown once, then aged out. Read in
        // mount() rather than in with(), because the flash is aged out at the end of this
        // request: read per-render it would vanish on the first unrelated action instead
        // of when the administrator says so.
        $flashed = session('revealed_secret');
        if (is_string($flashed) && $flashed !== '') {
            $this->revealedSecret = $flashed;
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

        $client->name = trim($this->editName);
        $client->redirect_uris = $redirects;
        $client->post_logout_redirect_uris = $postLogoutRedirects;
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
        $appUrl = config('app.url');

        return [
            // Route names differ per plane; one component, so it asks rather than assumes.
            'scopeRoute' => fn (string $name): string => app(ConsoleScope::class)->routeName($name),
            'client' => $client,
            'issuer' => rtrim(is_string($appUrl) ? $appUrl : '', '/'),
            // Read back from the grants rather than stored: they are what the token
            // endpoint enforces, so they cannot disagree with what this page claims.
            'appKind' => AppKind::forClient($client),
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
        <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch, var(--warning) 40%, transparent);background:var(--warning-soft)">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold" style="color:var(--warning-strong)">{{ $revealedIsFresh ? 'New client secret' : 'Your app is ready — connect it' }}</p>
                    <p class="mt-1 text-xs" style="color:var(--muted)">Copy the secret now — only a hash is stored, so it won't be shown again. If you lose it, rotate to issue a new one.</p>
                </div>
                <button type="button" wire:click="dismissSecret" class="btn btn-ghost btn-sm shrink-0">Done</button>
            </div>

            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg p-3" style="background:var(--surface-2);border:1px solid var(--border)">
                    <dt class="text-xs" style="color:var(--muted)">Issuer</dt>
                    <dd class="mono text-sm break-all select-all">{{ $issuer }}</dd>
                </div>
                <div class="rounded-lg p-3" style="background:var(--surface-2);border:1px solid var(--border)">
                    <dt class="text-xs" style="color:var(--muted)">Client ID</dt>
                    <dd class="mono text-sm break-all select-all">{{ $client->client_id }}</dd>
                </div>
                <div class="rounded-lg p-3 sm:col-span-2" style="background:var(--surface-2);border:1px solid var(--border)">
                    <dt class="text-xs font-semibold" style="color:var(--warning-strong)">Client secret — copy it now, it won't be shown again</dt>
                    <dd class="mono text-sm break-all select-all mt-1">{{ $revealedSecret }}</dd>
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
        {{-- THE STARTER, MATCHED TO WHAT THIS APP IS. It rendered only for
             authorization_code, so a CLI or a service — the two kinds where nobody
             has an existing snippet to adapt — got nothing at the one moment a
             copy-paste is worth most: the screen that shows the secret once.

             It also called an API the SDK does not have (`new CboxID(…)`,
             `id.signIn()`) and linked to `pypi.org/project/cbox-id`, which is not a
             package that exists. A snippet nobody ran is worse than no snippet: it
             is read as the documented way and fails in the reader's editor. --}}
        <div class="mt-4">
            <p class="text-xs font-medium uppercase mb-2" style="color:var(--muted);letter-spacing:0.06em">Wire it up</p>

            @if ($appKind === \App\Platform\AppKind::CliOrDevice)
            <pre class="rounded-lg p-3 overflow-x-auto text-xs mono" style="background:var(--surface-2);border:1px solid var(--border);line-height:1.6"><span style="color:var(--muted)">// npm i @cboxdk/id-js</span>
import { CboxIdClient } from '@cboxdk/id-js'

const cbox = new CboxIdClient({
  issuer: '{{ $issuer }}',
  clientId: '{{ $client->client_id }}',
  scopes: [{!! collect($client->scopes ?? [])->map(fn ($s) => "'".e($s)."'")->implode(', ') !!}],
})

const auth = await cbox.requestDeviceAuthorization()
console.log(`Open ${auth.verificationUri} and enter ${auth.userCode}`)

const user = await cbox.pollDeviceToken(auth) <span style="color:var(--muted)">// blocks until they approve</span></pre>
            @elseif ($appKind === \App\Platform\AppKind::Service)
            <pre class="rounded-lg p-3 overflow-x-auto text-xs mono" style="background:var(--surface-2);border:1px solid var(--border);line-height:1.6"><span style="color:var(--muted)"># A token for the service itself — no person involved.</span>
curl -X POST {{ $issuer }}/oauth/token \
  -d grant_type=client_credentials \
  -d client_id={{ $client->client_id }} \
  -d client_secret=$CBOX_ID_CLIENT_SECRET \
  -d scope="{{ implode(' ', $client->scopes ?? []) }}"</pre>
            @elseif ($appKind === \App\Platform\AppKind::Agent)
            <pre class="rounded-lg p-3 overflow-x-auto text-xs mono" style="background:var(--surface-2);border:1px solid var(--border);line-height:1.6"><span style="color:var(--muted)"># Ask a person to approve, on a device they already have.</span>
curl -X POST {{ $issuer }}/oauth/backchannel_authentication \
  -u {{ $client->client_id }}:$CBOX_ID_CLIENT_SECRET \
  -d login_hint=person@example.com \
  -d binding_message="Deploy release 4.2 to production" \
  -d scope="{{ implode(' ', $client->scopes ?? []) }}"</pre>
            @elseif (in_array('authorization_code', $client->grant_types ?? [], true))
            <pre class="rounded-lg p-3 overflow-x-auto text-xs mono" style="background:var(--surface-2);border:1px solid var(--border);line-height:1.6"><span style="color:var(--muted)">// npm i @cboxdk/id-js</span>
import { CboxIdClient } from '@cboxdk/id-js'

const cbox = new CboxIdClient({
  issuer: '{{ $issuer }}',
  clientId: '{{ $client->client_id }}',
  redirectUri: '{{ ($client->redirect_uris ?? [])[0] ?? 'https://app.example.com/auth/callback' }}',
  scopes: [{!! collect($client->scopes ?? [])->map(fn ($s) => "'".e($s)."'")->implode(', ') !!}],
})

<span style="color:var(--muted)">// On your sign-in route: persist state/codeVerifier/nonce, then redirect to req.url</span>
const req = await cbox.createAuthorizationRequest()

<span style="color:var(--muted)">// On your callback route:</span>
const user = await cbox.authenticate({ params, stored })</pre>
            @endif

            <p class="text-xs mt-2" style="color:var(--muted)">SDKs:
            <a href="https://www.npmjs.com/package/@cboxdk/id-js" class="underline" style="color:var(--accent-strong)">id-js</a> ·
            <a href="https://www.npmjs.com/package/@cboxdk/id-react" class="underline" style="color:var(--accent-strong)">id-react</a> ·
            <a href="https://packagist.org/packages/cboxdk/laravel-id-client" class="underline" style="color:var(--accent-strong)">laravel</a> ·
            <a href="https://github.com/cboxdk/id-go" class="underline" style="color:var(--accent-strong)">go</a>
            </p>
        </div>
    </div>

    {{-- Identifiers --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Credentials</h2>
        <div class="mt-4 space-y-3">
            <div>
                <p class="label">Client ID</p>
                <p class="mono text-xs rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $client->client_id }}</p>
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

    {{-- Grant types & scopes (read-only summary) --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Connection &amp; permissions</h2>
        <div class="mt-4 space-y-3">
            <div>
                <p class="label">Connects via</p>
                <div class="flex flex-wrap gap-1.5">
                    @if (in_array('authorization_code', $client->grant_types ?? [], true))
                        <span class="badge">Sign-in</span>
                    @endif
                    @if (in_array('client_credentials', $client->grant_types ?? [], true))
                        <span class="badge">API</span>
                    @endif
                    @if (($client->grant_types ?? []) === [])
                        <span class="text-sm" style="color:var(--faint)">—</span>
                    @endif
                </div>
            </div>
            <div>
                <p class="label">Scopes</p>
                @if (count($client->scopes ?? []) > 0)
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($client->scopes as $scope)
                            <span class="badge mono">{{ $scope }}</span>
                        @endforeach
                    </div>
                @else
                    <span class="text-sm" style="color:var(--faint)">—</span>
                @endif
            </div>
        </div>
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
