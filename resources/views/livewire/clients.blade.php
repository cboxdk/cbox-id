<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'API clients'])] class extends Component
{
    public string $name = '';

    /** confidential = has a secret (server-side); public = PKCE, no secret (SPA/native/mobile). */
    public string $type = 'confidential';

    public bool $grantClientCredentials = true;

    public bool $grantAuthorizationCode = false;

    public string $redirectUris = '';

    public string $scopes = '';

    /** First-party clients skip the consent screen and surface in the app launcher. */
    public bool $firstParty = false;

    public bool $creating = false;

    public ?string $newClientId = null;

    public ?string $newSecret = null;

    public function create(ClientRegistry $clients): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:confidential,public'],
            'grantClientCredentials' => ['boolean'],
            'grantAuthorizationCode' => ['boolean'],
            'scopes' => ['nullable', 'string', 'max:500'],
            'redirectUris' => ['nullable', 'string', 'max:2000'],
        ]);

        $grantTypes = $this->parsedGrantTypes();

        if ($grantTypes === []) {
            $this->addError('grantClientCredentials', 'Select at least one grant type.');

            return;
        }

        $redirects = $this->parsedRedirectUris();

        // Authorization-code clients redirect the browser back to themselves, so a
        // redirect URI is mandatory — and each must be an absolute URL we can trust.
        if (in_array('authorization_code', $grantTypes, true)) {
            if ($redirects === []) {
                $this->addError('redirectUris', 'Add at least one redirect URI for the authorization-code grant.');

                return;
            }
            foreach ($redirects as $uri) {
                if (filter_var($uri, FILTER_VALIDATE_URL) === false) {
                    $this->addError('redirectUris', 'Each redirect URI must be an absolute URL (e.g. https://app.example.com/callback).');

                    return;
                }
            }
        }

        $registered = $clients->register(new NewClient(
            name: $this->name,
            type: $this->type === 'public' ? ClientType::Public : ClientType::Confidential,
            redirectUris: $redirects,
            grantTypes: $grantTypes,
            scopes: $this->parsedScopes(),
            firstParty: $this->firstParty,
            organizationId: $this->orgId(),
        ));

        $this->newClientId = $registered->client->client_id;
        // Public (PKCE) clients have no secret; only reveal one when there is one.
        $this->newSecret = $registered->secret !== '' ? $registered->secret : null;

        $this->reset('name', 'scopes', 'redirectUris', 'creating', 'firstParty', 'grantAuthorizationCode');
        $this->type = 'confidential';
        $this->grantClientCredentials = true;

        session()->flash('status', 'Client "'.$registered->client->name.'" created.');
    }

    public function delete(string $clientId): void
    {
        $this->authorizeAdmin();

        Client::query()
            ->where('organization_id', $this->orgId())
            ->where('client_id', $clientId)
            ->delete();

        session()->flash('status', 'Client deleted.');
    }

    public function dismissSecret(): void
    {
        $this->reset('newClientId', 'newSecret');
    }

    /** @return list<string> */
    private function parsedGrantTypes(): array
    {
        $grants = [];
        if ($this->grantClientCredentials) {
            $grants[] = 'client_credentials';
        }
        if ($this->grantAuthorizationCode) {
            $grants[] = 'authorization_code';
            // A browser-login app almost always wants silent renewal too.
            $grants[] = 'refresh_token';
        }

        return $grants;
    }

    /** @return list<string> */
    private function parsedRedirectUris(): array
    {
        return $this->splitLines($this->redirectUris);
    }

    /** @return list<string> */
    private function parsedScopes(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', $this->scopes),
        ), fn (string $scope): bool => $scope !== ''));
    }

    /**
     * @return list<string>
     */
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
            'me' => app(CurrentUser::class),
            'rows' => Client::query()
                ->where('organization_id', $this->orgId())
                ->orderByDesc('id')
                ->get(),
        ];
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    public function boot(): void
    {
        // Read/write gate: this page exposes org-wide config (client secrets shown
        // once) — admins only. Enforced in boot() so it re-runs on every Livewire
        // action (create, delete), not just the initial mount.
        $this->authorizeAdmin();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }
}; ?>

<div>
    <div class="cbx-page-header mb-8">
        <div>
            <p class="cbx-page-eyebrow">Developers</p>
            <h1 class="cbx-page-title">API clients</h1>
            <p class="cbx-page-desc">OAuth clients & apps for this organization — machine-to-machine access, browser SSO, and first-party apps that appear in the launcher.</p>
        </div>
        <div class="flex items-center gap-2">
            @if ($me->isAdmin())
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New client</button>
            @endif
        </div>
    </div>

    @if ($newClientId)
        <div class="card p-4 mb-5" style="border-color:color-mix(in srgb, var(--warn) 40%, transparent);background:var(--warn-soft)">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    @if ($newSecret)
                        <p class="font-semibold text-sm" style="color:var(--warn)">Copy this secret now — it won't be shown again.</p>
                    @else
                        <p class="font-semibold text-sm" style="color:var(--foreground)">Public client created — no secret (uses PKCE).</p>
                    @endif
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>
                            <dt class="text-xs" style="color:var(--muted)">Client ID</dt>
                            <dd class="mono break-all">{{ $newClientId }}</dd>
                        </div>
                        @if ($newSecret)
                            <div>
                                <dt class="text-xs" style="color:var(--muted)">Client secret</dt>
                                <dd class="mono break-all">{{ $newSecret }}</dd>
                            </div>
                        @endif
                    </dl>
                </div>
                <button wire:click="dismissSecret" class="btn btn-ghost btn-sm">Dismiss</button>
            </div>
        </div>
    @endif

    @if ($creating && $me->isAdmin())
        <form wire:submit="create" class="card p-5 mb-5 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="name">Name</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="Billing service" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="type">Client type</label>
                    <select wire:model="type" id="type" class="select">
                        <option value="confidential">Confidential — has a secret (server-side)</option>
                        <option value="public">Public — PKCE, no secret (SPA / native / mobile)</option>
                    </select>
                </div>
            </div>

            <div>
                <span class="label">Grant types</span>
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    <label class="flex items-center gap-2 text-sm" style="color:var(--foreground)">
                        <input wire:model="grantClientCredentials" type="checkbox"> Client credentials <span style="color:var(--muted)">(machine-to-machine)</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm" style="color:var(--foreground)">
                        <input wire:model.live="grantAuthorizationCode" type="checkbox"> Authorization code <span style="color:var(--muted)">(browser login / SSO)</span>
                    </label>
                </div>
                @error('grantClientCredentials') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            @if ($grantAuthorizationCode)
                <div>
                    <label class="label" for="redirectUris">Redirect URIs <span style="color:var(--muted);font-weight:400">(one per line)</span></label>
                    <textarea wire:model="redirectUris" id="redirectUris" rows="3" class="input" style="height:auto;padding:8px 10px" placeholder="https://app.example.com/auth/callback"></textarea>
                    @error('redirectUris') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div>
                <label class="label" for="scopes">Scopes <span style="color:var(--muted);font-weight:400">(comma-separated)</span></label>
                <input wire:model="scopes" id="scopes" type="text" class="input" placeholder="users.read, orgs.read">
                @error('scopes') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-start gap-2.5 text-sm" style="color:var(--foreground)">
                <input wire:model="firstParty" type="checkbox" class="mt-0.5">
                <span>
                    First-party app
                    <span class="block text-xs" style="color:var(--muted)">Trusted app — skips the consent screen and appears in your team's app launcher (needs a redirect URI).</span>
                </span>
            </label>

            <div class="flex items-center gap-2 pt-1">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create client</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Client ID</th>
                        <th scope="col">Type</th>
                        <th scope="col">Grants</th>
                        <th scope="col">Scopes</th>
                        @if ($me->isAdmin())<th scope="col"><span class="sr-only">Actions</span></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $client)
                        <tr>
                            <td class="font-medium">
                                {{ $client->name }}
                                @if ($client->first_party)<span class="badge badge-success" style="margin-left:6px">First-party</span>@endif
                            </td>
                            <td class="mono text-xs" style="color:var(--muted)">{{ $client->client_id }}</td>
                            <td><span class="badge">{{ ucfirst($client->type->value) }}</span></td>
                            <td>
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($client->grant_types ?? [] as $grant)
                                        <span class="badge mono">{{ str_replace('_', ' ', $grant) }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td>
                                @if (count($client->scopes) > 0)
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($client->scopes as $scope)
                                            <span class="badge mono">{{ $scope }}</span>
                                        @endforeach
                                    </div>
                                @else
                                    <span style="color:var(--faint)">—</span>
                                @endif
                            </td>
                            @if ($me->isAdmin())
                                <td class="text-right">
                                    <button wire:click="delete('{{ $client->client_id }}')"
                                            wire:confirm="Delete this client? Anything using its credentials will stop working."
                                            class="btn btn-danger btn-sm">Delete</button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $me->isAdmin() ? 6 : 5 }}">
                                <div class="cbx-empty">
                                    <div class="cbx-empty-icon"><x-icon name="clients" class="w-5 h-5" /></div>
                                    <h3>No clients yet</h3>
                                    <p>Register an OAuth client for machine-to-machine access, browser SSO, or a first-party launcher app.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
