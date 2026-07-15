<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultGrant;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Token vault'])] class extends Component
{
    public bool $creating = false;

    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('required|string|max:190')]
    public string $provider = '';

    #[Validate('required|string')]
    public string $secret = '';

    /** The secret id whose inline rotate input is revealed. */
    public ?string $rotating = null;

    #[Validate('required|string')]
    public string $rotateSecret = '';

    /** The secret id whose grants expander is open. */
    public ?string $grantsFor = null;

    #[Validate('required|string|max:190')]
    public string $grantClient = '';

    public function boot(): void
    {
        abort_unless(app(CurrentUser::class)->isAdmin(), 403);
    }

    public function store(SecretVault $vault): void
    {
        $this->validateOnly('name');
        $this->validateOnly('provider');
        $this->validateOnly('secret');

        $vault->store($this->name, $this->provider, $this->secret, 'organization', $this->orgId());

        $this->reset('name', 'provider', 'secret', 'creating');
        session()->flash('status', 'Secret sealed and stored — its value is never shown again.');
    }

    public function startRotate(string $id): void
    {
        $this->authorizeSecret($id);
        $this->rotating = $id;
        $this->rotateSecret = '';
    }

    public function rotate(string $id, SecretVault $vault): void
    {
        $this->authorizeSecret($id);
        $this->validateOnly('rotateSecret');

        $vault->rotate($id, $this->rotateSecret);

        $this->reset('rotating', 'rotateSecret');
        session()->flash('status', 'Secret rotated — the sealed value was replaced.');
    }

    public function revoke(string $id, SecretVault $vault): void
    {
        $this->authorizeSecret($id);

        $vault->revoke($id);
        session()->flash('status', 'Secret revoked — no future lease can open it.');
    }

    public function toggleGrants(string $id): void
    {
        $this->authorizeSecret($id);
        $this->grantsFor = $this->grantsFor === $id ? null : $id;
        $this->grantClient = '';
    }

    public function addGrant(string $id, SecretVault $vault): void
    {
        $this->authorizeSecret($id);
        $this->validateOnly('grantClient');

        $vault->grant($id, $this->grantClient);

        $this->reset('grantClient');
        session()->flash('status', 'Access granted.');
    }

    public function revokeGrant(string $id, string $clientId, SecretVault $vault): void
    {
        $this->authorizeSecret($id);

        $vault->revokeGrant($id, $clientId);
        session()->flash('status', 'Access revoked.');
    }

    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            'secrets' => VaultSecret::query()
                ->where('owner_type', 'organization')
                ->where('owner_id', $this->orgId())
                ->orderByDesc('id')
                ->get(),
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
     * Guard org isolation: an action id must resolve to a secret owned by the
     * acting admin's organization within this environment, or it is unknown.
     */
    private function authorizeSecret(string $id): void
    {
        abort_unless(
            VaultSecret::query()
                ->whereKey($id)
                ->where('owner_type', 'organization')
                ->where('owner_id', $this->orgId())
                ->exists(),
            404,
        );
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }
}; ?>

<div>
    <div class="cbx-page-header">
        <div>
            <p class="cbx-page-eyebrow">Security</p>
            <h1 class="cbx-page-title">Token vault</h1>
            <p class="cbx-page-desc">Downstream API keys your AI agents present to providers. Each value is sealed at rest and brokered only to explicitly granted clients — it is never shown again after you store it.</p>
        </div>
        @if ($me->isAdmin())
            <button wire:click="$set('creating', true)" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New secret</button>
        @endif
    </div>

    @if ($creating)
        <form wire:submit="store" class="card p-4 mb-5 space-y-3">
            <div class="grid gap-3" style="grid-template-columns:minmax(0,1fr) minmax(0,1fr)">
                <div>
                    <label class="label" for="name">Name</label>
                    <input wire:model="name" id="name" class="input" placeholder="openai-prod" autofocus>
                    @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="provider">Provider</label>
                    <input wire:model="provider" id="provider" class="input" placeholder="openai">
                    @error('provider') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="label" for="secret">Secret value</label>
                <input wire:model="secret" id="secret" type="password" class="input mono" placeholder="sk-live-…" autocomplete="off">
                @error('secret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                <p class="text-xs mt-1" style="color:var(--faint)">Sealed on store. This is the only time it is handled in the clear — it is never echoed back.</p>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Seal &amp; store</button>
                <button type="button" wire:click="$set('creating', false)" class="btn btn-ghost">Cancel</button>
            </div>
        </form>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Name</th><th>Provider</th><th>Status</th><th>Rotated</th><th></th></tr></thead>
                <tbody>
                @forelse ($secrets as $s)
                    <tr>
                        <td class="font-medium">{{ $s->name }}</td>
                        <td><span class="badge mono">{{ $s->provider }}</span></td>
                        <td>
                            @if ($s->isRevoked())
                                <span class="cbx-pill cbx-pill--destructive"><span class="dot"></span>Revoked</span>
                            @elseif ($s->isExpired())
                                <span class="cbx-pill cbx-pill--warning"><span class="dot"></span>Expired</span>
                            @else
                                <span class="cbx-pill cbx-pill--success"><span class="dot"></span>Active</span>
                            @endif
                        </td>
                        <td class="text-xs" style="color:var(--muted)">{{ $s->rotated_at?->diffForHumans() ?? '—' }}</td>
                        <td class="text-right whitespace-nowrap">
                            @unless ($s->isRevoked())
                                <button wire:click="startRotate('{{ $s->id }}')" class="btn btn-ghost btn-sm">Rotate</button>
                            @endunless
                            <button wire:click="toggleGrants('{{ $s->id }}')" class="btn btn-ghost btn-sm">Grants</button>
                            @unless ($s->isRevoked())
                                <button wire:click="revoke('{{ $s->id }}')" wire:confirm="Revoke this secret? No future lease can open it — this cannot be undone." class="btn btn-ghost btn-sm" style="color:var(--danger)">Revoke</button>
                            @endunless
                        </td>
                    </tr>

                    @if ($rotating === $s->id)
                        <tr>
                            <td colspan="5">
                                <form wire:submit="rotate('{{ $s->id }}')" class="flex items-end gap-2 flex-wrap">
                                    <div class="flex-1" style="min-width:16rem">
                                        <label class="label" for="rotate-{{ $s->id }}">New value for {{ $s->name }}</label>
                                        <input wire:model="rotateSecret" id="rotate-{{ $s->id }}" type="password" class="input mono" placeholder="sk-live-…" autocomplete="off" autofocus>
                                        @error('rotateSecret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">Rotate</button>
                                    <button type="button" wire:click="$set('rotating', null)" class="btn btn-ghost btn-sm">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    @endif

                    @if ($grantsFor === $s->id)
                        <tr>
                            <td colspan="5">
                                <p class="label mb-2">Client grants for {{ $s->name }}</p>
                                @forelse ($grants as $g)
                                    <div class="cbx-row">
                                        <span class="mono">{{ $g->client_id }}</span>
                                        <button wire:click="revokeGrant('{{ $s->id }}', '{{ $g->client_id }}')" wire:confirm="Revoke this client's access?" class="btn btn-ghost btn-sm" style="color:var(--danger)">Revoke</button>
                                    </div>
                                @empty
                                    <p class="text-xs mb-2" style="color:var(--faint)">No clients are authorized to lease this secret.</p>
                                @endforelse
                                @unless ($s->isRevoked())
                                    <form wire:submit="addGrant('{{ $s->id }}')" class="flex items-end gap-2 mt-2 flex-wrap">
                                        <div class="flex-1" style="min-width:16rem">
                                            <label class="label" for="grant-{{ $s->id }}">Authorize a client</label>
                                            <input wire:model="grantClient" id="grant-{{ $s->id }}" class="input mono" placeholder="agent-client-1">
                                            @error('grantClient') <p class="field-error" role="alert">{{ $message }}</p> @enderror
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">Add grant</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="5">
                        <div class="cbx-empty">
                            <div class="cbx-empty-icon"><x-icon name="key" class="w-5 h-5" /></div>
                            <h3>No secrets yet</h3>
                            <p>Store a downstream API key to broker it to your agents.</p>
                        </div>
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
