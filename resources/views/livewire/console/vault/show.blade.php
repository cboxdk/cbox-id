<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\Console\VaultScope;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultGrant;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Console › Token vault › detail — one component, both planes. The full, deep-linkable
 * lifecycle for one downstream credential: metadata, rotation, the client grants that
 * authorize an agent to lease it, and revocation.
 *
 * The sealed value is NEVER displayed — not the stored value, not a rotated one. A new
 * value is handled in the clear only in the rotate input, the one time the administrator
 * types it, and is sealed and cleared on submit.
 *
 * EVERY read and every mutation goes through {@see VaultScope}, which derives the owner
 * from the CONSOLE'S scope. The environment plane's version derived it from the row
 * (`VaultOwner::fromRow($secret->owner_type, $secret->owner_id)`) and handed the
 * framework's deny-by-default owner check its own answer — a tautology that authorized
 * every row against itself. An id outside this scope now resolves to nothing and is a
 * 404, which tells the caller nothing about what exists elsewhere.
 */
new #[Layout('components.layouts.console', ['title' => 'Stored token'])] class extends Component
{
    /**
     * Second layer — see the index page. boot(), not mount(), so it re-runs on every
     * Livewire message rather than only the first render.
     */
    public function boot(): void
    {
        app(ConsoleScope::class)->assertMayAdminister();
    }

    public string $secretId = '';

    /** Whether the rotate input is revealed. */
    public bool $rotating = false;

    #[Validate('required|string')]
    public string $rotateSecret = '';

    #[Validate('required|string|max:190')]
    public string $grantClient = '';

    public function mount(string $secret): void
    {
        $this->secretId = $this->secret($secret)->id;
    }

    /**
     * Resolve a secret this console's scope owns, or refuse.
     *
     * `$secretId` is a public wire property, so on every action it is whatever the client
     * says it is — which is precisely why the lookup is re-done here per call rather than
     * trusted from mount().
     */
    private function secret(?string $id = null): VaultSecret
    {
        $model = app(VaultScope::class)->find($id ?? $this->secretId);

        abort_if($model === null, 404);

        return $model;
    }

    public function startRotate(): void
    {
        $this->secret();
        $this->rotating = true;
        $this->rotateSecret = '';
    }

    public function rotate(SecretVault $vault): void
    {
        $secret = $this->secret();
        $this->validateOnly('rotateSecret');

        $vault->rotate($secret->id, $this->rotateSecret, app(VaultScope::class)->owner());

        $this->reset('rotating', 'rotateSecret');
        $this->dispatch('toast', message: 'Secret rotated — the sealed value was replaced.');
    }

    public function addGrant(SecretVault $vault): void
    {
        $secret = $this->secret();
        $this->validateOnly('grantClient');

        $vault->grant($secret->id, $this->grantClient, app(VaultScope::class)->owner());

        $this->reset('grantClient');
        $this->dispatch('toast', message: 'Access granted.');
    }

    public function revokeGrant(string $clientId, SecretVault $vault): void
    {
        $vault->revokeGrant($this->secret()->id, $clientId, app(VaultScope::class)->owner());
        $this->dispatch('toast', message: 'Access revoked.');
    }

    public function revoke(SecretVault $vault): mixed
    {
        $vault->revoke($this->secret()->id, app(VaultScope::class)->owner());

        $this->dispatch('toast', message: 'Secret revoked — no future lease can open it.');

        return $this->redirectRoute(app(ConsoleScope::class)->routeName('vault'), navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $secret = $this->secret();

        return [
            'secret' => $secret,
            'grants' => VaultGrant::query()
                ->where('secret_id', $secret->id)
                ->whereNull('revoked_at')
                ->orderBy('client_id')
                ->get(),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <a href="{{ route(app(\App\Platform\Console\ConsoleScope::class)->routeName('vault')) }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Token vault</a>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">{{ $secret->name }}</h1>
            <span class="badge mono">{{ $secret->provider }}</span>
            @if ($secret->isRevoked())
                <span class="badge badge-danger">Revoked</span>
            @elseif ($secret->isExpired())
                <span class="badge badge-warn">Expired</span>
            @else
                <span class="badge badge-success">Active</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $secret->id }}</p>
    </div>

    {{-- Metadata. The sealed value is never displayed here — only its shape. --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Details</h2>
        <dl class="mt-4 grid gap-4 sm:grid-cols-2 text-sm">
            <div>
                <dt class="label">Provider</dt>
                <dd class="mono">{{ $secret->provider }}</dd>
            </div>
            <div>
                <dt class="label">Scope</dt>
                <dd>{{ $secret->owner_type === 'organization' ? 'Organization' : 'Environment-wide' }}</dd>
            </div>
            <div>
                <dt class="label">Rotated</dt>
                <dd style="color:var(--muted)">{{ $secret->rotated_at?->diffForHumans() ?? 'never' }}</dd>
            </div>
            <div>
                <dt class="label">Expires</dt>
                <dd style="color:var(--muted)">{{ $secret->expires_at?->diffForHumans() ?? 'never' }}</dd>
            </div>
        </dl>
    </div>

    {{-- Rotation. The new value is handled in the clear only in this input, the one
         time it is typed; it is sealed and cleared on submit and never echoed back. --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <p class="text-sm font-medium">Rotate</p>
            @unless ($secret->isRevoked())
                @unless ($rotating)
                    <button type="button" wire:click="startRotate" class="btn btn-ghost btn-sm">Rotate</button>
                @endunless
            @endunless
        </div>
        @if ($secret->isRevoked())
            <p class="mt-2 text-sm" style="color:var(--muted)">This secret is revoked — it can no longer be rotated.</p>
        @elseif ($rotating)
            <form wire:submit="rotate" class="mt-4 flex items-end gap-2 flex-wrap">
                <div class="flex-1" style="min-width:16rem">
                    <label class="label" for="rotateSecret">New value for {{ $secret->name }}</label>
                    <input wire:model="rotateSecret" id="rotateSecret" type="password" class="input mono" placeholder="sk-live-…" autocomplete="off" autofocus>
                    @error('rotateSecret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="rotate">Rotate</button>
                <button type="button" wire:click="$set('rotating', false)" class="btn btn-ghost btn-sm">Cancel</button>
            </form>
            <div class="mt-4 rounded-xl border p-5" style="border-color:color-mix(in oklch,var(--warning) 35%,transparent);background:var(--warning-soft);color:var(--warning-strong)">
                <p class="text-sm font-medium">This is the only time the value is handled in the clear.</p>
                <p class="mt-1 text-xs">It replaces the sealed value on rotate and is never shown again — keep your own copy if you need one.</p>
            </div>
        @else
            <p class="mt-2 text-sm" style="color:var(--muted)">Replace the sealed value with a new credential. The stored value is never shown.</p>
        @endif
    </div>

    {{-- Client grants. Deny-by-default: only listed clients may lease this secret. --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Client grants</h2>
        <div class="mt-4 space-y-2">
            @forelse ($grants as $g)
                <div class="flex items-center justify-between gap-2 rounded-lg px-3 py-2" style="background:var(--surface-2)" wire:key="grant-{{ $g->client_id }}">
                    <span class="mono text-xs break-all">{{ $g->client_id }}</span>
                    @php $revokeGrantAction = "revokeGrant('{$g->client_id}')"; @endphp
                    <x-confirm-delete
                        :name="$g->client_id"
                        :action="$revokeGrantAction"
                        label="Revoke"
                        trigger-class="btn btn-ghost btn-sm shrink-0"
                        trigger-style="color:var(--destructive)"
                        consequence="This client can no longer lease the secret. Any lease it already holds stays valid until it expires." />
                </div>
            @empty
                <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">No clients are authorized to lease this secret.</p>
            @endforelse
        </div>
        @unless ($secret->isRevoked())
            <form wire:submit="addGrant" class="mt-4 flex items-end gap-2 flex-wrap">
                <div class="flex-1" style="min-width:16rem">
                    <label class="label" for="grantClient">Authorize a client</label>
                    <input wire:model="grantClient" id="grantClient" type="text" class="input mono" placeholder="agent-client-1">
                    @error('grantClient') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="addGrant">Add grant</button>
            </form>
        @endunless
    </div>

    {{-- Revocation. Immediate and permanent — no future lease can open the secret. --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <h2 class="cbx-section-title">Danger zone</h2>
        @unless ($secret->isRevoked())
            <p class="mt-2 text-sm" style="color:var(--muted)">Revoking is immediate and permanent — no future lease can open this secret.</p>
            <div class="mt-4">
                <x-confirm-delete
                    :name="$secret->name"
                    action="revoke"
                    label="Revoke secret"
                    consequence="No future lease can open this secret. Revocation is immediate and permanent." />
            </div>
        @else
            <p class="mt-2 text-sm" style="color:var(--muted)">This secret is revoked — no future lease can open it.</p>
        @endunless
    </div>
</div>
