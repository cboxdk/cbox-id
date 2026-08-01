<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use App\Rules\SecureRedirectUri;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Applications › detail. The full, deep-linkable lifecycle
 * for one OAuth client: identifiers, editable name & redirect URIs, secret rotation and
 * delete.
 *
 * Every mutation re-resolves the target within THIS environment (the Client model's
 * BelongsToEnvironment scope) and 404s otherwise — an id from another plane never
 * matches (deny-by-default).
 *
 * The client secret is never stored in the clear: only its SHA-256 hash lives in the
 * database. It is shown exactly once — freshly on creation (handed over by flash) or
 * when rotated here — and can never be echoed again.
 */
new #[Layout('components.layouts.environment', ['title' => 'Application'])] class extends Component
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

    public string $clientId = '';

    public string $editName = '';

    public string $editRedirectUris = '';

    /** Where sign-out may send people back to. Validated exactly like redirect URIs. */
    public string $editPostLogoutRedirectUris = '';

    /** Plaintext secret, held only for the single render that reveals it. Protected so it is
     *  never dehydrated into the wire snapshot — revealed once, then gone on rehydration. */
    protected ?string $revealedSecret = null;

    public bool $revealedIsFresh = false;

    public function mount(string $client): void
    {
        $model = $this->resolve($client);

        $this->clientId = $model->id;
        $this->editName = $model->name;
        $this->editRedirectUris = implode("\n", $model->redirect_uris ?? []);
        $this->editPostLogoutRedirectUris = implode("\n", $model->post_logout_redirect_uris ?? []);

        // A secret handed over by the create page — shown once, then aged out.
        $flashed = session('revealed_secret');
        if (is_string($flashed) && $flashed !== '') {
            $this->revealedSecret = $flashed;
        }
    }

    /**
     * Resolve a client THIS environment owns, or refuse. whereKey is environment-scoped
     * (BelongsToEnvironment), so an id from another plane resolves to null and 404s —
     * never a cross-tenant read or write.
     */
    private function resolve(string $key): Client
    {
        $model = Client::query()->whereKey($key)->first();
        abort_if($model === null, 404);

        return $model;
    }

    private function client(): Client
    {
        return $this->resolve($this->clientId);
    }

    public function saveDetails(): void
    {
        $client = $this->client();

        $data = $this->validate([
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

        $this->dispatch('toast', message: 'Application updated.');
    }

    /**
     * Overlap-rotate the secret: mint a fresh one, persist only its hash, and reveal the
     * plaintext once. Public clients have no secret, so rotation is refused for them.
     */
    public function rotateSecret(): void
    {
        $client = $this->client();

        if ($client->type !== ClientType::Confidential) {
            $this->dispatch('toast', message: 'Public clients use PKCE and have no secret to rotate.');

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
            $this->dispatch('toast', message: 'This client signs assertions with its own keys and has no secret. Rotate the key in its JWKS instead.', severity: 'error');

            return;
        }

        $secret = 'csec_'.bin2hex(random_bytes(32));
        $client->secret_hash = hash('sha256', $secret);
        $client->save();

        $this->revealedSecret = $secret;
        $this->revealedIsFresh = true;

        $this->dispatch('toast', message: 'A new secret was issued — copy it now, it will not be shown again.');
    }

    public function dismissSecret(): void
    {
        $this->reset('revealedIsFresh');
        $this->revealedSecret = null;
    }

    public function deleteClient(): mixed
    {
        $this->client()->delete();

        $this->dispatch('toast', message: 'Application deleted.');

        return $this->redirectRoute('environment.clients', navigate: true);
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
        return [
            'client' => $this->client(),
            // Protected → never dehydrated; passed explicitly so the secret renders once.
            'revealedSecret' => $this->revealedSecret,
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route('environment.clients') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Applications</a>
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
         is the single moment the plaintext exists — it can never be echoed again. --}}
    @if ($revealedSecret)
        <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch, var(--warning) 40%, transparent);background:var(--warning-soft)">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold" style="color:var(--warning-strong)">{{ $revealedIsFresh ? 'New client secret' : 'Client secret' }} — copy it now, it won't be shown again</p>
                    <p class="mt-1 text-xs" style="color:var(--muted)">Only a hash is stored. If you lose it, rotate to issue a new one.</p>
                </div>
                <button type="button" wire:click="dismissSecret" class="btn btn-ghost btn-sm shrink-0">Done</button>
            </div>
            <p class="mt-3 mono text-sm rounded-lg px-3 py-2 select-all break-all" style="background:var(--surface-2);border:1px solid var(--border)">{{ $revealedSecret }}</p>
        </div>
    @endif

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
                    <p class="text-sm" style="color:var(--muted)">None — this is a public client and uses PKCE instead of a secret.</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Details --}}
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

    {{-- Secret rotation --}}
    {{-- Not shown for a client that authenticates with its own keys: there is no secret
         to rotate, and the copy below ("the current one stops working") would be false —
         the button would CREATE a credential rather than replace one. --}}
    @if ($client->type === ClientType::Confidential && $client->jwks === null)
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
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Delete application</h2>
        <p class="mt-1 text-sm" style="color:var(--muted)">Anything using its credentials will stop working. This cannot be undone.</p>
        {{-- Irreversible AND cross-tenant-visible in effect: every integration using
             these credentials stops working. A native confirm named neither the app nor
             the environment, and Enter dismissed it. --}}
        <div class="mt-4">
            <x-confirm-delete
                :name="$editName"
                action="deleteClient"
                label="Delete application"
                consequence="Anything using this application's credentials will stop working immediately. This cannot be undone." />
        </div>
    </div>
</div>
