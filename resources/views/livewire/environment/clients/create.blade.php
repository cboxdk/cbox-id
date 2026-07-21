<?php

declare(strict_types=1);

use App\Platform\ScopeCatalog;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Applications › New. A dedicated, deep-linkable create
 * page for registering an OAuth client in this environment. The registry stamps the
 * environment scope, so the client is env-owned from birth.
 *
 * A confidential client is issued a secret exactly once. We flash the plaintext into
 * the session and route to the detail page, which reveals it a single time in a
 * warning-tinted callout — only the SHA-256 hash is stored, so it is never retrievable
 * again (rotate mints a fresh one, shown once).
 */
new #[Layout('components.layouts.environment', ['title' => 'New application'])] class extends Component
{
    public string $name = '';

    /** confidential = has a secret (server-side); public = PKCE, no secret (SPA/native/mobile). */
    public string $type = 'confidential';

    public bool $grantAuthorizationCode = true;

    public bool $grantClientCredentials = false;

    public string $redirectUris = '';

    /** @var list<string> Scopes ticked from the catalog. */
    public array $selectedScopes = ['openid', 'profile', 'email'];

    /** Advanced: any extra custom scope keys, comma-separated. */
    public string $customScopes = '';

    /** First-party clients skip the consent screen and surface in the app launcher. */
    public bool $firstParty = false;

    public string $manifestUrl = '';

    public function create(ClientRegistry $clients, ScopeCatalog $catalog): mixed
    {
        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:confidential,public'],
            'grantAuthorizationCode' => ['boolean'],
            'grantClientCredentials' => ['boolean'],
            'customScopes' => ['nullable', 'string', 'max:500'],
            'redirectUris' => ['nullable', 'string', 'max:2000'],
            'manifestUrl' => ['nullable', 'url', 'max:500'],
        ]);

        $grantTypes = $this->parsedGrantTypes();

        if ($grantTypes === []) {
            $this->addError('grantAuthorizationCode', 'Choose at least one way this app connects.');

            return null;
        }

        $redirects = $this->splitLines($this->redirectUris);

        if (in_array('authorization_code', $grantTypes, true)) {
            if ($redirects === []) {
                $this->addError('redirectUris', 'A browser-login app needs at least one redirect URI to return people to.');

                return null;
            }
            foreach ($redirects as $uri) {
                if (filter_var($uri, FILTER_VALIDATE_URL) === false) {
                    $this->addError('redirectUris', 'Each redirect URI must be an absolute URL (e.g. https://app.example.com/callback).');

                    return null;
                }
            }
        }

        // Only catalog scopes from the picker, plus any advanced custom keys.
        $scopes = array_values(array_unique(array_merge(
            array_values(array_intersect($this->selectedScopes, $catalog->keys())),
            $this->parsedCustomScopes(),
        )));

        $registered = $clients->register(new NewClient(
            name: trim($this->name),
            type: $this->type === 'public' ? ClientType::Public : ClientType::Confidential,
            redirectUris: $redirects,
            grantTypes: $grantTypes,
            scopes: $scopes,
            firstParty: $this->firstParty,
        ));

        // A published manifest URL (the pull transport) — stored on the app so the
        // scheduled sweep + "Sync now" can fetch its declared roles/permissions.
        if (trim($this->manifestUrl) !== '') {
            $registered->client->forceFill(['manifest_url' => trim($this->manifestUrl)])->save();
        }

        // The plaintext secret exists only here. Hand it to the detail page once via a
        // flash — it is aged out after that render, so it is never shown a second time.
        if ($registered->secret !== null && $registered->secret !== '') {
            session()->flash('revealed_secret', $registered->secret);
        }

        $this->dispatch('toast', message: 'Application "'.$registered->client->name.'" created.');

        return $this->redirectRoute('environment.clients.show', ['client' => $registered->client->id], navigate: true);
    }

    /** @return list<string> */
    private function parsedGrantTypes(): array
    {
        $grants = [];
        if ($this->grantAuthorizationCode) {
            $grants[] = 'authorization_code';
            $grants[] = 'refresh_token';
        }
        if ($this->grantClientCredentials) {
            $grants[] = 'client_credentials';
        }

        return $grants;
    }

    /** @return list<string> */
    private function parsedCustomScopes(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', $this->customScopes),
        ), fn (string $scope): bool => $scope !== ''));
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
    public function with(ScopeCatalog $catalog): array
    {
        return ['scopeGroups' => $catalog->grouped()];
    }
}; ?>

<div>
    <a href="{{ route('environment.clients') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Applications</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New application</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Connect an app to this environment — for signing people in (single sign-on) or for machine-to-machine API access.</p>

    <form wire:submit="create" class="mt-6 max-w-2xl rounded-xl border p-5 space-y-5" style="border-color:var(--border)">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Application name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="Support Portal" autofocus>
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="type">Client type</label>
                <select wire:model="type" id="type" class="select">
                    <option value="confidential">Confidential — a server that can keep a secret</option>
                    <option value="public">Public — a browser/mobile app (PKCE, no secret)</option>
                </select>
                @error('type') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <span class="label">How does this app connect?</span>
            <div class="mt-1 flex flex-wrap gap-x-6 gap-y-2">
                <label class="flex items-center gap-2 text-sm">
                    <input wire:model.live="grantAuthorizationCode" type="checkbox" class="rounded"> Sign people in <span style="color:var(--muted)">(single sign-on)</span>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input wire:model.live="grantClientCredentials" type="checkbox" class="rounded"> Call the API as itself <span style="color:var(--muted)">(machine-to-machine)</span>
                </label>
            </div>
            @error('grantAuthorizationCode') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        @if ($grantAuthorizationCode)
            <div>
                <label class="label" for="redirectUris">Redirect URIs <span style="color:var(--faint);font-weight:400">— where Cbox ID sends people back (one per line)</span></label>
                <textarea wire:model="redirectUris" id="redirectUris" rows="2" class="input mono" style="height:auto;padding:8px 10px;font-size:0.78rem" placeholder="https://app.example.com/auth/callback"></textarea>
                @error('redirectUris') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        @endif

        <div>
            <span class="label">Permissions this app requests</span>
            <p class="mt-1 text-xs" style="color:var(--muted)">Scopes decide what the app is allowed to see and do. People see the sign-in ones on the consent screen (first-party apps skip it).</p>
            <div class="mt-3 space-y-4">
                @foreach ($scopeGroups as $group => $scopes)
                    <div>
                        <p class="text-xs font-semibold uppercase mb-2" style="color:var(--muted);letter-spacing:0.05em">{{ $group }}</p>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($scopes as $scope)
                                <label class="flex items-start gap-2.5 rounded-lg p-2.5 cursor-pointer" style="border:1px solid var(--border)">
                                    <input wire:model="selectedScopes" type="checkbox" value="{{ $scope['key'] }}" class="mt-0.5 rounded">
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2 flex-wrap">
                                            <span class="text-sm font-medium">{{ $scope['label'] }}</span>
                                            <span class="text-xs rounded-full px-2 py-0.5 mono" style="background:var(--surface-2);color:var(--muted)">{{ $scope['key'] }}</span>
                                        </span>
                                        <span class="block text-xs mt-0.5" style="color:var(--muted)">{{ $scope['description'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <div>
                    <label class="label" for="customScopes" style="font-weight:400;font-size:0.75rem">Advanced — custom scopes <span style="color:var(--faint)">(comma-separated)</span></label>
                    <input wire:model="customScopes" id="customScopes" type="text" class="input mono" placeholder="reports.read">
                    @error('customScopes') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <label class="flex items-start gap-2.5 text-sm">
            <input wire:model="firstParty" type="checkbox" class="mt-0.5 rounded">
            <span>
                First-party application
                <span class="block text-xs" style="color:var(--muted)">A trusted app you own — skips the consent screen and appears in the app launcher (needs a redirect URI).</span>
            </span>
        </label>

        <div>
            <label class="label" for="manifestUrl">Manifest URL <span style="color:var(--faint);font-weight:400">— optional; where the app publishes its roles &amp; permissions</span></label>
            <input wire:model="manifestUrl" id="manifestUrl" type="url" class="input mono" placeholder="https://app.example.com/.well-known/cbox-authz">
            <p class="mt-1 text-xs" style="color:var(--muted)">Cbox ID pulls this to learn the app's roles. You can also set it later, or the app can push.</p>
            @error('manifestUrl') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center gap-2 pt-1">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create application</button>
            <a href="{{ route('environment.clients') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
