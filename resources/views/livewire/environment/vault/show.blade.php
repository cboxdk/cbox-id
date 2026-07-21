<?php

declare(strict_types=1);

use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Cbox\Id\TokenVault\Models\VaultGrant;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Stored tokens › detail. The full, deep-linkable
 * lifecycle for one downstream credential: metadata, rotation, the client grants that
 * authorize an agent to lease it, and revocation.
 *
 * The sealed value is NEVER displayed — not the stored value, not a rotated one. A new
 * value is handled in the clear only in the rotate input, the one time the admin types
 * it, and is sealed and cleared on submit. Every read/write re-resolves the secret
 * within THIS environment (BelongsToEnvironment) and 404s on a foreign id.
 */
new #[Layout('components.layouts.environment', ['title' => 'Stored token'])] class extends Component
{
    public string $secretId = '';

    /** Whether the rotate input is revealed. */
    public bool $rotating = false;

    #[Validate('required|string')]
    public string $rotateSecret = '';

    #[Validate('required|string|max:190')]
    public string $grantClient = '';

    public function mount(string $secret): void
    {
        $model = VaultSecret::query()->whereKey($secret)->first();
        abort_if($model === null, 404);

        $this->secretId = $model->id;
    }

    /**
     * Resolve the secret THIS environment owns, or refuse. The query is
     * environment-scoped (BelongsToEnvironment), so an id from another plane resolves
     * to null and is a 404 — never a cross-tenant mutation (deny-by-default).
     */
    private function secret(): VaultSecret
    {
        $model = VaultSecret::query()->whereKey($this->secretId)->first();
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

        $vault->rotate($secret->id, $this->rotateSecret, VaultOwner::fromRow($secret->owner_type, $secret->owner_id));

        $this->reset('rotating', 'rotateSecret');
        session()->flash('status', 'Secret rotated — the sealed value was replaced.');
    }

    public function addGrant(SecretVault $vault): void
    {
        $secret = $this->secret();
        $this->validateOnly('grantClient');

        $vault->grant($secret->id, $this->grantClient, VaultOwner::fromRow($secret->owner_type, $secret->owner_id));

        $this->reset('grantClient');
        session()->flash('status', 'Access granted.');
    }

    public function revokeGrant(string $clientId, SecretVault $vault): void
    {
        $vault->revokeGrant($this->secret()->id, $clientId, VaultOwner::fromRow($this->secret()->owner_type, $this->secret()->owner_id));
        session()->flash('status', 'Access revoked.');
    }

    public function revoke(SecretVault $vault): mixed
    {
        $vault->revoke($this->secret()->id, VaultOwner::fromRow($this->secret()->owner_type, $this->secret()->owner_id));

        session()->flash('status', 'Secret revoked — no future lease can open it.');

        return $this->redirectRoute('environment.vault', navigate: true);
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
        <a href="{{ route('environment.vault') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Stored tokens</a>
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
            @if ($secret->owner_type === 'organization')
                <span class="badge">Org-scoped</span>
            @endif
        </div>
        <p class="mt-1 text-sm mono" style="color:var(--faint)">{{ $secret->id }}</p>
    </div>

    {{-- Metadata. The sealed value is never displayed here — only its shape. --}}
    <div class="rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Details</p>
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
        <p class="text-sm font-medium">Client grants</p>
        <div class="mt-4 space-y-2">
            @forelse ($grants as $g)
                <div class="flex items-center justify-between gap-2 rounded-lg px-3 py-2" style="background:var(--surface-2)" wire:key="grant-{{ $g->client_id }}">
                    <span class="mono text-xs break-all">{{ $g->client_id }}</span>
                    <button wire:click="revokeGrant('{{ $g->client_id }}')" wire:confirm="Revoke this client's access?" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)">Revoke</button>
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
        <p class="text-sm font-medium">Danger zone</p>
        @unless ($secret->isRevoked())
            <p class="mt-2 text-sm" style="color:var(--muted)">Revoking is immediate and permanent — no future lease can open this secret.</p>
            <button type="button" class="btn btn-ghost btn-sm mt-4" style="color:var(--destructive)" wire:click="revoke" wire:confirm="Revoke this secret? No future lease can open it — this cannot be undone.">Delete secret</button>
        @else
            <p class="mt-2 text-sm" style="color:var(--muted)">This secret is revoked — no future lease can open it.</p>
        @endunless
    </div>
</div>
