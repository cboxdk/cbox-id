<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\ScopeCatalog;
use Cbox\Id\AccessControl\AppManifestPuller;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Apps & API keys'])] class extends Component
{
    public string $name = '';

    /** confidential = has a secret (server-side); public = PKCE, no secret (SPA/native/mobile). */
    public string $type = 'confidential';

    public bool $grantClientCredentials = false;

    public bool $grantAuthorizationCode = true;

    public string $redirectUris = '';

    /** @var array<int, string> Scopes ticked from the catalog. */
    public array $selectedScopes = ['openid', 'profile', 'email'];

    /** Advanced: any extra custom scope keys, comma-separated. */
    public string $customScopes = '';

    /** First-party clients skip the consent screen and surface in the app launcher. */
    public bool $firstParty = false;

    public bool $creating = false;

    public ?string $newClientId = null;

    public ?string $newSecret = null;

    /** @var array{redirect: ?string, scopes: array<int, string>, auth_code: bool}|null */
    public ?array $newClientMeta = null;

    /** Where this app publishes its role/permission manifest for Cbox ID to pull. */
    public string $manifestUrl = '';

    /** The app whose manifest panel is expanded, if any. */
    public ?string $managingManifest = null;

    public string $editManifestUrl = '';

    public function create(ClientRegistry $clients, ScopeCatalog $catalog): void
    {
        $this->authorizeAdmin();

        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:confidential,public'],
            'grantClientCredentials' => ['boolean'],
            'grantAuthorizationCode' => ['boolean'],
            'customScopes' => ['nullable', 'string', 'max:500'],
            'redirectUris' => ['nullable', 'string', 'max:2000'],
            'manifestUrl' => ['nullable', 'url', 'max:500'],
        ]);

        $grantTypes = $this->parsedGrantTypes();

        if ($grantTypes === []) {
            $this->addError('grantClientCredentials', 'Choose at least one way this app connects.');

            return;
        }

        $redirects = $this->splitLines($this->redirectUris);

        if (in_array('authorization_code', $grantTypes, true)) {
            if ($redirects === []) {
                $this->addError('redirectUris', 'A browser-login app needs at least one redirect URI to return people to.');

                return;
            }
            foreach ($redirects as $uri) {
                if (filter_var($uri, FILTER_VALIDATE_URL) === false) {
                    $this->addError('redirectUris', 'Each redirect URI must be an absolute URL (e.g. https://app.example.com/callback).');

                    return;
                }
            }
        }

        // Only catalog scopes from the picker, plus any advanced custom keys.
        $scopes = array_values(array_unique(array_merge(
            array_values(array_intersect($this->selectedScopes, $catalog->keys())),
            $this->parsedCustomScopes(),
        )));

        $registered = $clients->register(new NewClient(
            name: $this->name,
            type: $this->type === 'public' ? ClientType::Public : ClientType::Confidential,
            redirectUris: $redirects,
            grantTypes: $grantTypes,
            scopes: $scopes,
            firstParty: $this->firstParty,
            organizationId: $this->orgId(),
        ));

        // A published manifest URL (the pull transport) — stored on the app so the
        // scheduled sweep + "Sync now" can fetch its declared roles/permissions.
        if (trim($this->manifestUrl) !== '') {
            $registered->client->forceFill(['manifest_url' => trim($this->manifestUrl)])->save();
        }

        $this->newClientId = $registered->client->client_id;
        $this->newSecret = ($registered->secret !== null && $registered->secret !== '') ? $registered->secret : null;
        $this->newClientMeta = [
            'redirect' => $redirects[0] ?? null,
            'scopes' => $scopes,
            'auth_code' => in_array('authorization_code', $grantTypes, true),
        ];

        $this->reset('name', 'redirectUris', 'creating', 'firstParty', 'customScopes', 'manifestUrl');
        $this->type = 'confidential';
        $this->grantClientCredentials = false;
        $this->grantAuthorizationCode = true;
        $this->selectedScopes = $catalog->signInDefaults();

        session()->flash('status', 'App "'.$registered->client->name.'" created.');
    }

    public function delete(string $clientId): void
    {
        $this->authorizeAdmin();

        Client::query()
            ->where('organization_id', $this->orgId())
            ->where('client_id', $clientId)
            ->delete();

        session()->flash('status', 'App deleted.');
    }

    public function dismissSecret(): void
    {
        $this->reset('newClientId', 'newSecret', 'newClientMeta');
    }

    public function openManifest(string $clientId): void
    {
        $this->authorizeAdmin();

        if ($this->managingManifest === $clientId) {
            $this->managingManifest = null;

            return;
        }

        $this->managingManifest = $clientId;
        $this->editManifestUrl = $this->findClient($clientId)?->manifest_url ?? '';
    }

    public function saveManifestUrl(string $clientId, AppManifestPuller $puller): void
    {
        $this->authorizeAdmin();
        $this->validate(['editManifestUrl' => ['nullable', 'url', 'max:500']]);

        $client = $this->findClient($clientId);
        if ($client === null) {
            return;
        }

        $client->forceFill(['manifest_url' => trim($this->editManifestUrl) ?: null])->save();

        if ($client->manifest_url === null) {
            session()->flash('status', 'Manifest URL cleared.');

            return;
        }

        // Pull immediately so the app's roles appear without waiting for the sweep.
        try {
            $result = $puller->pull($client->refresh());
            session()->flash('status', $result !== null ? 'Manifest synced — '.$result->rolesDeclared.' role(s), '.$result->permissionsDeclared.' permission(s).' : 'Saved.');
        } catch (\Throwable $e) {
            $this->addError('editManifestUrl', 'Saved, but the sync failed: '.$e->getMessage());
        }
    }

    public function syncNow(string $clientId, AppManifestPuller $puller): void
    {
        $this->authorizeAdmin();

        $client = $this->findClient($clientId);
        if ($client === null || $client->manifest_url === null) {
            return;
        }

        try {
            $result = $puller->pull($client);
            session()->flash('status', $result !== null && ! $result->unchanged
                ? 'Synced — '.$result->rolesDeclared.' role(s).'
                : 'Already up to date.');
        } catch (\Throwable $e) {
            session()->flash('status', 'Sync failed: '.$e->getMessage());
        }
    }

    private function findClient(string $clientId): ?Client
    {
        return Client::query()
            ->where('organization_id', $this->orgId())
            ->where('client_id', $clientId)
            ->first();
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
            $grants[] = 'refresh_token';
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
    public function with(ScopeCatalog $catalog): array
    {
        $appUrl = config('app.url');

        $rows = Client::query()
            ->where('organization_id', $this->orgId())
            ->orderByDesc('id')
            ->get();

        // How many roles each app currently declares (for the Manifest panel).
        $roleCounts = Role::query()
            ->whereIn('client_id', $rows->pluck('client_id'))
            ->whereNull('orphaned_at')
            ->get(['client_id'])
            ->groupBy('client_id')
            ->map(fn ($group) => $group->count());

        return [
            'me' => app(CurrentUser::class),
            'scopeGroups' => $catalog->grouped(),
            'issuer' => rtrim(is_string($appUrl) ? $appUrl : '', '/'),
            'rows' => $rows,
            'roleCounts' => $roleCounts,
        ];
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }

    public function boot(): void
    {
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
            <h1 class="cbx-page-title">Apps &amp; API keys</h1>
            <p class="cbx-page-desc">Connect your apps to Cbox ID — for signing people in (single sign-on) or for machine-to-machine API access. First-party apps also appear in your team's launcher.</p>
        </div>
        <div class="flex items-center gap-2">
            @if ($me->isAdmin())
                <button wire:click="$toggle('creating')" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New app</button>
            @endif
        </div>
    </div>

    @if ($newClientId)
        <div class="card p-5 mb-5" style="border-color:var(--accent-edge)">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h3 class="font-semibold" style="color:var(--foreground)">Your app is ready — connect it</h3>
                    <p class="text-sm" style="color:var(--muted)">Copy these values into your app or SDK. The secret is shown once.</p>
                </div>
                <button wire:click="dismissSecret" class="btn btn-ghost btn-sm">Done</button>
            </div>

            <dl class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg p-3" style="background:var(--secondary)">
                    <dt class="text-xs" style="color:var(--muted)">Issuer</dt>
                    <dd class="mono text-sm break-all" style="color:var(--foreground)">{{ $issuer }}</dd>
                </div>
                <div class="rounded-lg p-3" style="background:var(--secondary)">
                    <dt class="text-xs" style="color:var(--muted)">Client ID</dt>
                    <dd class="mono text-sm break-all" style="color:var(--foreground)">{{ $newClientId }}</dd>
                </div>
                @if ($newSecret)
                    <div class="rounded-lg p-3 sm:col-span-2" style="background:var(--warning-soft);border:1px solid color-mix(in oklch, var(--warning) 30%, transparent)">
                        <dt class="text-xs font-semibold" style="color:var(--warning)">Client secret — copy it now, it won't be shown again</dt>
                        <dd class="mono text-sm break-all mt-1" style="color:var(--foreground)">{{ $newSecret }}</dd>
                    </div>
                @else
                    <div class="rounded-lg p-3 sm:col-span-2" style="background:var(--secondary)">
                        <dt class="text-xs" style="color:var(--muted)">Client secret</dt>
                        <dd class="text-sm mt-1" style="color:var(--foreground)">None — this is a public app and uses PKCE instead of a secret.</dd>
                    </div>
                @endif
            </dl>

            @if ($newClientMeta && $newClientMeta['auth_code'])
                <div class="mt-4">
                    <p class="text-xs font-medium uppercase mb-2" style="color:var(--muted);letter-spacing:0.06em">Wire it up with a Cbox&nbsp;ID SDK</p>
                    <pre class="rounded-lg p-3 overflow-x-auto text-xs mono" style="background:var(--secondary);color:var(--foreground);line-height:1.6"><span style="color:var(--muted)">// npm i @cboxdk/id-js</span>
import {'{'} CboxID {'}'} from '@cboxdk/id-js'

const id = new CboxID({'{'}
  issuer: '{{ $issuer }}',
  clientId: '{{ $newClientId }}',
  redirectUri: '{{ $newClientMeta['redirect'] }}',
  scopes: [{!! collect($newClientMeta['scopes'])->map(fn ($s) => "'".e($s)."'")->implode(', ') !!}],
{'}'})

await id.signIn() <span style="color:var(--muted)">// redirects to Cbox ID, returns signed in</span></pre>
                    <p class="text-xs mt-2" style="color:var(--muted)">SDKs:
                        <a href="https://www.npmjs.com/package/@cboxdk/id-js" class="underline" style="color:var(--accent)">id-js</a> ·
                        <a href="https://www.npmjs.com/package/@cboxdk/id-react" class="underline" style="color:var(--accent)">id-react</a> ·
                        <a href="https://pypi.org/project/cbox-id/" class="underline" style="color:var(--accent)">python</a>
                    </p>
                </div>
            @endif
        </div>
    @endif

    @if ($creating && $me->isAdmin())
        <form wire:submit="create" class="card p-5 mb-5 space-y-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="name">App name</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="Support Portal" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="type">Client type</label>
                    <select wire:model="type" id="type" class="select">
                        <option value="confidential">Confidential — a server that can keep a secret</option>
                        <option value="public">Public — a browser/mobile app (PKCE, no secret)</option>
                    </select>
                </div>
            </div>

            {{-- How the app connects — with a visual of the handshake. --}}
            <div>
                <span class="label">How does this app connect?</span>
                <div class="flex flex-wrap gap-x-6 gap-y-2 mb-3">
                    <label class="flex items-center gap-2 text-sm" style="color:var(--foreground)">
                        <input wire:model.live="grantAuthorizationCode" type="checkbox"> Sign people in <span style="color:var(--muted)">(single sign-on)</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm" style="color:var(--foreground)">
                        <input wire:model.live="grantClientCredentials" type="checkbox"> Call the API as itself <span style="color:var(--muted)">(machine-to-machine)</span>
                    </label>
                </div>
                @error('grantClientCredentials') <p class="field-error">{{ $message }}</p> @enderror

                @if ($grantAuthorizationCode)
                    <div class="cbx-flow" role="img" aria-label="Sign-in handshake: person, your app, Cbox ID, back to your app">
                        <div class="cbx-flow-step"><span class="cbx-flow-dot" style="background:var(--info-soft);color:var(--info)">1</span><span>Person clicks <b>Sign in</b> in your app</span></div>
                        <span class="cbx-flow-arrow">→</span>
                        <div class="cbx-flow-step"><span class="cbx-flow-dot" style="background:var(--accent-soft);color:var(--primary)">2</span><span>Redirected to <b>Cbox ID</b> to authenticate</span></div>
                        <span class="cbx-flow-arrow">→</span>
                        <div class="cbx-flow-step"><span class="cbx-flow-dot" style="background:var(--accent-soft);color:var(--primary)">3</span><span>Redirected <b>back to your app</b> with a code</span></div>
                        <span class="cbx-flow-arrow">→</span>
                        <div class="cbx-flow-step"><span class="cbx-flow-dot" style="background:var(--success-soft);color:var(--success)">4</span><span>Your app swaps the code for tokens</span></div>
                    </div>
                @endif
                @if ($grantClientCredentials)
                    <div class="cbx-flow" role="img" aria-label="Machine-to-machine: your app requests a token, then calls the API">
                        <div class="cbx-flow-step"><span class="cbx-flow-dot" style="background:var(--accent-soft);color:var(--primary)">1</span><span>Your app sends its <b>ID + secret</b> to Cbox ID</span></div>
                        <span class="cbx-flow-arrow">→</span>
                        <div class="cbx-flow-step"><span class="cbx-flow-dot" style="background:var(--accent-soft);color:var(--primary)">2</span><span>Cbox ID returns an <b>access token</b></span></div>
                        <span class="cbx-flow-arrow">→</span>
                        <div class="cbx-flow-step"><span class="cbx-flow-dot" style="background:var(--success-soft);color:var(--success)">3</span><span>Your app calls the <b>API</b> with the token</span></div>
                    </div>
                @endif
            </div>

            @if ($grantAuthorizationCode)
                <div>
                    <label class="label" for="redirectUris">Redirect URIs <span style="color:var(--muted);font-weight:400">— where Cbox ID sends people back (one per line)</span></label>
                    <textarea wire:model="redirectUris" id="redirectUris" rows="2" class="input" style="height:auto;padding:8px 10px" placeholder="https://app.example.com/auth/callback"></textarea>
                    @error('redirectUris') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif

            {{-- Permissions / scopes — a described picker, not a blank box. --}}
            <div>
                <span class="label">Permissions this app requests</span>
                <p class="text-xs mb-3" style="color:var(--muted)">Scopes decide what the app is allowed to see and do. People see the sign-in ones on the consent screen (first-party apps skip it).</p>
                <div class="space-y-4">
                    @foreach ($scopeGroups as $group => $scopes)
                        <div>
                            <p class="text-xs font-semibold uppercase mb-2" style="color:var(--muted);letter-spacing:0.05em">{{ $group }}</p>
                            <div class="grid gap-2 sm:grid-cols-2">
                                @foreach ($scopes as $scope)
                                    <label class="flex items-start gap-2.5 rounded-lg p-2.5 cursor-pointer" style="border:1px solid var(--border)">
                                        <input wire:model="selectedScopes" type="checkbox" value="{{ $scope['key'] }}" class="mt-0.5">
                                        <span class="min-w-0">
                                            <span class="flex items-center gap-2">
                                                <span class="text-sm font-medium" style="color:var(--foreground)">{{ $scope['label'] }}</span>
                                                <span class="badge mono" style="font-size:10px">{{ $scope['key'] }}</span>
                                            </span>
                                            <span class="block text-xs mt-0.5" style="color:var(--muted)">{{ $scope['description'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                    <div>
                        <label class="label" for="customScopes" style="font-weight:400;font-size:12px">Advanced — custom scopes <span style="color:var(--muted)">(comma-separated)</span></label>
                        <input wire:model="customScopes" id="customScopes" type="text" class="input" placeholder="reports.read">
                        @error('customScopes') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <label class="flex items-start gap-2.5 text-sm" style="color:var(--foreground)">
                <input wire:model="firstParty" type="checkbox" class="mt-0.5">
                <span>
                    First-party app
                    <span class="block text-xs" style="color:var(--muted)">A trusted app you own — skips the consent screen and appears in your team's app launcher (needs a redirect URI).</span>
                </span>
            </label>

            <div>
                <label class="label" for="manifestUrl">Manifest URL <span style="color:var(--muted);font-weight:400">— optional; where the app publishes its roles &amp; permissions</span></label>
                <input wire:model="manifestUrl" id="manifestUrl" type="url" class="input" placeholder="https://app.example.com/.well-known/cbox-authz">
                <p class="text-xs mt-1" style="color:var(--muted)">Cbox ID pulls this to learn the app's roles. You can also set it later, or the app can push.</p>
                @error('manifestUrl') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-2 pt-1">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Create app</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">App</th>
                        <th scope="col">Client ID</th>
                        <th scope="col">Type</th>
                        <th scope="col">Connects via</th>
                        <th scope="col">Permissions</th>
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
                                    @if (in_array('authorization_code', $client->grant_types ?? [], true))<span class="badge">Sign-in</span>@endif
                                    @if (in_array('client_credentials', $client->grant_types ?? [], true))<span class="badge">API</span>@endif
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
                                    <div class="flex items-center justify-end gap-2">
                                        <button wire:click="openManifest('{{ $client->client_id }}')" class="btn btn-ghost btn-sm">Roles{{ ($roleCounts[$client->client_id] ?? 0) > 0 ? ' · '.$roleCounts[$client->client_id] : '' }}</button>
                                        <button wire:click="delete('{{ $client->client_id }}')"
                                                wire:confirm="Delete this app? Anything using its credentials will stop working."
                                                class="btn btn-danger btn-sm">Delete</button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                        @if ($managingManifest === $client->client_id && $me->isAdmin())
                            <tr>
                                <td colspan="6" style="background:color-mix(in oklch, var(--secondary) 55%, transparent);padding:16px 20px">
                                    <p class="text-sm font-medium mb-1" style="color:var(--foreground)">Roles & permissions for {{ $client->name }}</p>
                                    <p class="text-xs mb-3" style="color:var(--muted)">The app declares these — Cbox ID pulls them from its manifest URL (or the app pushes). They become assignable on the <a href="{{ route('members') }}" class="underline" style="color:var(--accent)">Members</a> page.</p>
                                    <form wire:submit="saveManifestUrl('{{ $client->client_id }}')" class="flex flex-wrap items-end gap-2 mb-3">
                                        <div class="flex-1 min-w-[18rem]">
                                            <label class="label" for="mf-{{ $client->client_id }}">Manifest URL</label>
                                            <input wire:model="editManifestUrl" id="mf-{{ $client->client_id }}" type="url" class="input" placeholder="https://app.example.com/.well-known/cbox-authz">
                                            @error('editManifestUrl') <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">Save & sync</button>
                                        @if ($client->manifest_url)
                                            <button type="button" wire:click="syncNow('{{ $client->client_id }}')" class="btn btn-ghost btn-sm">Sync now</button>
                                        @endif
                                    </form>
                                    @php $declared = $roleCounts[$client->client_id] ?? 0; @endphp
                                    <p class="text-xs" style="color:var(--muted)">
                                        @if ($declared > 0)
                                            {{ $declared }} role(s) declared. See them on <a href="{{ route('roles') }}" class="underline" style="color:var(--accent)">Roles</a>.
                                        @else
                                            No roles declared yet — set a manifest URL above and sync, or have the app push its manifest.
                                        @endif
                                    </p>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ $me->isAdmin() ? 6 : 5 }}">
                                <div class="cbx-empty">
                                    <div class="cbx-empty-icon"><x-icon name="clients" class="w-5 h-5" /></div>
                                    <h3>No apps yet</h3>
                                    <p>Connect your first app — to sign people in with single sign-on, or to call the Cbox ID API.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
