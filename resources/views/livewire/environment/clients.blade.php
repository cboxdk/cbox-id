<?php

declare(strict_types=1);

use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Applications. Lists and registers the OAuth/OIDC
 * clients your apps use to sign users in against THIS environment's IdP. Env-scoped
 * (hard scope, host-resolved); the secret is shown exactly once at creation.
 */
new #[Layout('components.layouts.environment', ['title' => 'Applications'])] class extends Component
{
    public string $name = '';

    public string $type = 'confidential';

    public string $redirectUri = '';

    /** The one-time client secret, shown once and never persisted in the clear. */
    public ?string $freshSecret = null;

    public ?string $freshClientId = null;

    public function create(ClientRegistry $clients): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:public,confidential'],
            'redirectUri' => ['nullable', 'url', 'max:2000'],
        ]);

        $uris = trim($this->redirectUri) !== '' ? [trim($this->redirectUri)] : [];

        $registered = $clients->register(new NewClient(
            name: trim($this->name),
            type: ClientType::from($this->type),
            redirectUris: $uris,
            grantTypes: $uris !== [] ? ['authorization_code', 'refresh_token'] : ['client_credentials'],
            scopes: ['openid', 'profile', 'email'],
        ));

        $this->freshSecret = $registered->secret;
        $this->freshClientId = $registered->client->client_id;
        $this->reset('name', 'redirectUri');
        $this->type = 'confidential';
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return ['clients' => Client::query()->orderByDesc('created_at')->limit(100)->get()];
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Applications</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">The OAuth/OIDC clients your apps use to sign in against this environment.</p>

    @if ($freshSecret !== null || $freshClientId !== null)
        <div class="mt-6 rounded-xl border p-4" style="border-color:color-mix(in oklch,var(--success) 35%,transparent);background:var(--success-soft)">
            <p class="text-sm font-medium" style="color:var(--success)">Application created. Copy the secret now — you won't see it again.</p>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex items-center gap-2">
                    <dt class="w-24 shrink-0" style="color:var(--muted)">Client ID</dt>
                    <dd class="flex-1 min-w-0"><code class="block truncate rounded-lg px-3 py-2 mono" style="background:var(--background);border:1px solid var(--border)">{{ $freshClientId }}</code></dd>
                </div>
                @if ($freshSecret !== null)
                    <div class="flex items-center gap-2">
                        <dt class="w-24 shrink-0" style="color:var(--muted)">Secret</dt>
                        <dd class="flex-1 min-w-0 flex items-center gap-2">
                            <code class="flex-1 min-w-0 truncate rounded-lg px-3 py-2 mono" style="background:var(--background);border:1px solid var(--border)">{{ $freshSecret }}</code>
                            <button type="button" class="btn btn-primary btn-sm shrink-0" data-copy="{{ $freshSecret }}" onclick="navigator.clipboard.writeText(this.getAttribute('data-copy'))">Copy</button>
                        </dd>
                    </div>
                @endif
            </dl>
        </div>
    @endif

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($clients as $client)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="font-medium truncate">{{ $client->name }}</span>
                        <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $client->type->value }}</span>
                        @if ($client->first_party)
                            <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">first-party</span>
                        @endif
                    </div>
                    <p class="text-sm truncate mono" style="color:var(--muted)">{{ $client->client_id }}</p>
                </div>
            </div>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No applications yet — create one to start signing users in.</p>
        @endforelse
    </div>

    <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Register an application</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">A redirect URI makes it a browser (authorization-code) app; leave it blank for a machine-to-machine (client-credentials) client.</p>
        <form wire:submit="create" class="mt-4 space-y-3">
            <div class="grid sm:grid-cols-[1fr_auto] gap-2">
                <div>
                    <input wire:model="name" type="text" class="input" placeholder="My web app" aria-label="Application name">
                    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <select wire:model="type" class="input" style="width:auto" aria-label="Client type">
                    <option value="confidential">Confidential</option>
                    <option value="public">Public (SPA/mobile)</option>
                </select>
            </div>
            <div>
                <input wire:model="redirectUri" type="url" class="input mono" placeholder="https://app.example.com/callback (optional)" aria-label="Redirect URI">
                @error('redirectUri') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Register application</button>
        </form>
    </div>
</div>
