<?php

declare(strict_types=1);

use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultGrant;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Stored tokens — the downstream credential vault.
 * Brokers the API keys and tokens this environment's AI agents present to third
 * parties (OpenAI, GitHub, …). Each value is sealed at rest and released only to
 * explicitly granted clients; it is NEVER echoed back after it is stored.
 *
 * Secrets and grants are environment-owned (BelongsToEnvironment), so every lookup
 * resolves only within this environment — an id from another plane never matches,
 * closing cross-tenant id tampering. Access is gated by the env-admin session (route
 * middleware), so the account member has full CRUD here; there is no per-org
 * entitlement lock and, unlike the org console, no sudo step at the control plane.
 * A secret may optionally be scoped to one of the environment's organizations.
 */
new #[Layout('components.layouts.environment', ['title' => 'Stored tokens'])] class extends Component
{
    public bool $creating = false;

    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('required|string|max:190')]
    public string $provider = '';

    #[Validate('required|string')]
    public string $secret = '';

    /** The organization the new secret is scoped to, or '' for environment-wide. */
    public string $ownerId = '';

    /** The secret id whose inline rotate input is revealed. */
    public ?string $rotating = null;

    #[Validate('required|string')]
    public string $rotateSecret = '';

    /** The secret id whose grants expander is open. */
    public ?string $grantsFor = null;

    #[Validate('required|string|max:190')]
    public string $grantClient = '';

    public function store(SecretVault $vault): void
    {
        $this->validateOnly('name');
        $this->validateOnly('provider');
        $this->validateOnly('secret');

        // Scope to an organization only when one is chosen; otherwise the secret is
        // environment-wide. Both stay within this environment (BelongsToEnvironment).
        if ($this->ownerId !== '') {
            $vault->store($this->name, $this->provider, $this->secret, 'organization', $this->ownerId);
        } else {
            $vault->store($this->name, $this->provider, $this->secret);
        }

        $this->reset('name', 'provider', 'secret', 'ownerId', 'creating');
        session()->flash('status', 'Secret sealed and stored — its value is never shown again.');
    }

    public function startRotate(string $id): void
    {
        $this->ownedSecret($id);
        $this->rotating = $id;
        $this->rotateSecret = '';
    }

    public function rotate(string $id, SecretVault $vault): void
    {
        $this->ownedSecret($id);
        $this->validateOnly('rotateSecret');

        $vault->rotate($id, $this->rotateSecret);

        $this->reset('rotating', 'rotateSecret');
        session()->flash('status', 'Secret rotated — the sealed value was replaced.');
    }

    public function revoke(string $id, SecretVault $vault): void
    {
        $this->ownedSecret($id);

        $vault->revoke($id);
        session()->flash('status', 'Secret revoked — no future lease can open it.');
    }

    public function toggleGrants(string $id): void
    {
        $this->ownedSecret($id);
        $this->grantsFor = $this->grantsFor === $id ? null : $id;
        $this->grantClient = '';
    }

    public function addGrant(string $id, SecretVault $vault): void
    {
        $this->ownedSecret($id);
        $this->validateOnly('grantClient');

        $vault->grant($id, $this->grantClient);

        $this->reset('grantClient');
        session()->flash('status', 'Access granted.');
    }

    public function revokeGrant(string $id, string $clientId, SecretVault $vault): void
    {
        $this->ownedSecret($id);

        $vault->revokeGrant($id, $clientId);
        session()->flash('status', 'Access revoked.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'secrets' => VaultSecret::query()
                ->orderByDesc('id')
                ->get(),
            'organizations' => Organization::query()->orderBy('name')->get(),
            'grants' => $this->grantsFor !== null
                ? VaultGrant::query()
                    ->where('secret_id', $this->grantsFor)
                    ->whereNull('revoked_at')
                    ->orderBy('client_id')
                    ->get()
                : collect(),
        ];
    }

    /**
     * Resolve a secret THIS environment owns, or refuse. The query is
     * environment-scoped (BelongsToEnvironment), so an id from another plane resolves
     * to null and is a 404 — never a cross-tenant mutation (deny-by-default).
     */
    private function ownedSecret(string $id): VaultSecret
    {
        $secret = VaultSecret::query()->find($id);

        abort_if($secret === null, 404);

        return $secret;
    }
}; ?>

<div>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Stored tokens</h1>
            <p class="mt-1 text-sm" style="color:var(--muted)">Downstream API keys your AI agents present to providers. Each value is sealed at rest and brokered only to explicitly granted clients — it is never shown again after you store it.</p>
        </div>
        <button wire:click="$toggle('creating')" class="btn btn-primary shrink-0"><x-icon name="plus" class="w-4 h-4" /> New secret</button>
    </div>

    @if ($creating)
        <form wire:submit="store" class="mt-6 rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="name">Name</label>
                    <input wire:model="name" id="name" type="text" class="input" placeholder="openai-prod" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="provider">Provider</label>
                    <input wire:model="provider" id="provider" type="text" class="input" placeholder="openai">
                    @error('provider') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="owner">Scope</label>
                    <select wire:model="ownerId" id="owner" class="select">
                        <option value="">Environment-wide</option>
                        @foreach ($organizations as $org)
                            <option value="{{ $org->id }}">{{ $org->name }}</option>
                        @endforeach
                    </select>
                    @error('ownerId') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="secret">Secret value</label>
                    <input wire:model="secret" id="secret" type="password" class="input mono" placeholder="sk-live-…" autocomplete="off">
                    @error('secret') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Write-only handling: the value is handled in the clear this one time and
                 sealed on store — it is never echoed back, so warn before submitting. --}}
            <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch,var(--warning) 35%,transparent);background:var(--warning-soft);color:var(--warning)">
                <p class="text-sm font-medium">This is the only time the value is handled in the clear.</p>
                <p class="mt-1 text-xs">It is sealed on store and never shown again — keep your own copy if you need one.</p>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Seal &amp; store</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($secrets as $s)
            <div class="rounded-xl border p-5" style="border-color:var(--border)">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="font-semibold truncate">{{ $s->name }}</p>
                            <span class="text-xs rounded-full px-2 py-0.5 mono" style="background:var(--surface-2);color:var(--muted)">{{ $s->provider }}</span>
                            @if ($s->isRevoked())
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--destructive-soft);color:var(--destructive)">Revoked</span>
                            @elseif ($s->isExpired())
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--warning-soft);color:var(--warning)">Expired</span>
                            @else
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--accent-soft);color:var(--accent)">Active</span>
                            @endif
                            @if ($s->owner_type === 'organization')
                                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">Org-scoped</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs" style="color:var(--faint)">Rotated {{ $s->rotated_at?->diffForHumans() ?? 'never' }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        @unless ($s->isRevoked())
                            <button wire:click="startRotate('{{ $s->id }}')" class="btn btn-ghost btn-sm">Rotate</button>
                        @endunless
                        <button wire:click="toggleGrants('{{ $s->id }}')" class="btn btn-ghost btn-sm">Grants</button>
                        @unless ($s->isRevoked())
                            <button wire:click="revoke('{{ $s->id }}')" wire:confirm="Revoke this secret? No future lease can open it — this cannot be undone." class="btn btn-ghost btn-sm" style="color:var(--destructive)">Revoke</button>
                        @endunless
                    </div>
                </div>

                @if ($rotating === $s->id)
                    <form wire:submit="rotate('{{ $s->id }}')" class="mt-4 flex items-end gap-2 flex-wrap">
                        <div class="flex-1" style="min-width:16rem">
                            <label class="label" for="rotate-{{ $s->id }}">New value for {{ $s->name }}</label>
                            <input wire:model="rotateSecret" id="rotate-{{ $s->id }}" type="password" class="input mono" placeholder="sk-live-…" autocomplete="off" autofocus>
                            @error('rotateSecret') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">Rotate</button>
                        <button type="button" wire:click="$set('rotating', null)" class="btn btn-ghost btn-sm">Cancel</button>
                    </form>
                @endif

                @if ($grantsFor === $s->id)
                    <div class="mt-4">
                        <p class="label">Client grants for {{ $s->name }}</p>
                        <div class="mt-2 space-y-2">
                            @forelse ($grants as $g)
                                <div class="flex items-center justify-between gap-2 rounded-lg px-3 py-2" style="background:var(--surface-2)">
                                    <span class="mono text-xs break-all">{{ $g->client_id }}</span>
                                    <button wire:click="revokeGrant('{{ $s->id }}', '{{ $g->client_id }}')" wire:confirm="Revoke this client's access?" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)">Revoke</button>
                                </div>
                            @empty
                                <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">No clients are authorized to lease this secret.</p>
                            @endforelse
                        </div>
                        @unless ($s->isRevoked())
                            <form wire:submit="addGrant('{{ $s->id }}')" class="mt-3 flex items-end gap-2 flex-wrap">
                                <div class="flex-1" style="min-width:16rem">
                                    <label class="label" for="grant-{{ $s->id }}">Authorize a client</label>
                                    <input wire:model="grantClient" id="grant-{{ $s->id }}" type="text" class="input mono" placeholder="agent-client-1">
                                    @error('grantClient') <p class="field-error">{{ $message }}</p> @enderror
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">Add grant</button>
                            </form>
                        @endunless
                    </div>
                @endif
            </div>
        @empty
            <p class="rounded-xl border p-4 text-sm" style="border-color:var(--border);color:var(--muted)">No secrets yet. Store a downstream API key to broker it to this environment's agents.</p>
        @endforelse
    </div>
</div>
