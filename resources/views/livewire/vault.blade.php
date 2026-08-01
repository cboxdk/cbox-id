<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\Models\VaultGrant;
use Cbox\Id\TokenVault\Models\VaultSecret;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
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

        $vault->store($this->name, $this->provider, $this->secret, VaultOwner::organization($this->orgId()));

        $this->reset('name', 'provider', 'secret', 'creating');
        $this->dispatch('toast', message: 'Secret sealed and stored — its value is never shown again.');
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

        $vault->rotate($id, $this->rotateSecret, VaultOwner::organization($this->orgId()));

        $this->reset('rotating', 'rotateSecret');
        $this->dispatch('toast', message: 'Secret rotated — the sealed value was replaced.');
    }

    public function revoke(string $id, SecretVault $vault): void
    {
        $this->authorizeSecret($id);

        $vault->revoke($id, VaultOwner::organization($this->orgId()));
        $this->dispatch('toast', message: 'Secret revoked — no future lease can open it.');
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

        $vault->grant($id, $this->grantClient, VaultOwner::organization($this->orgId()));

        $this->reset('grantClient');
        $this->dispatch('toast', message: 'Access granted.');
    }

    public function revokeGrant(string $id, string $clientId, SecretVault $vault): void
    {
        $this->authorizeSecret($id);

        $vault->revokeGrant($id, $clientId, VaultOwner::organization($this->orgId()));
        $this->dispatch('toast', message: 'Access revoked.');
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        return [
            'me' => app(CurrentUser::class),
            'secrets' => VaultSecret::query()
                ->where('owner_type', 'organization')
                ->where('owner_id', $this->orgId())
                ->orderByDesc('id')
                ->get(),
            // `grantsFor` is a public wire property, so it is whatever the client says.
            // Joining through a secret this ORG owns is what makes it safe: without it,
            // `$set('grantsFor', '<another org's secret id>')` listed that org's grants —
            // no plaintext, but the client ids and the existence of the grant leaked.
            'grants' => $this->grantsFor !== null && $this->ownsSecret($this->grantsFor)
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
        abort_unless($this->ownsSecret($id), 404);
    }

    /**
     * The same ownership question, asked where a RENDER rather than an action needs it:
     * the grants expander keys on a public wire property, so it must not be trusted to
     * name a secret this organization holds.
     */
    private function ownsSecret(string $id): bool
    {
        return VaultSecret::query()
            ->whereKey($id)
            ->where('owner_type', 'organization')
            ->where('owner_id', $this->orgId())
            ->exists();
    }

    private function orgId(): string
    {
        return app(CurrentUser::class)->organizationId() ?? '';
    }
}; ?>

<div>
    <x-page-header title="Token vault" :help="\App\Platform\Help\HelpTopic::TokenVault"
                   subtitle="API keys your apps and agents present to other services. Each value is sealed at rest, handed only to the apps you grant, and never shown again after you store it.">
        @if ($me->isAdmin())
            <x-slot:actions>
                <button wire:click="$set('creating', true)" class="btn btn-primary"><x-icon name="plus" class="w-4 h-4" /> New secret</button>
            </x-slot:actions>
        @endif
    </x-page-header>

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
                    <tr wire:key="secret-{{ $s->id }}">
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
                                <x-confirm-delete
                                    :name="$s->name"
                                    :action="'revoke(\''.$s->id.'\')'"
                                    label="Revoke"
                                    consequence="No future lease can open this secret. This cannot be undone." />
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
                                        @php $revokeGrantAction = "revokeGrant('{$s->id}', '{$g->client_id}')"; @endphp
                                        <x-confirm-delete
                                            :name="$g->client_id"
                                            :action="$revokeGrantAction"
                                            label="Revoke"
                                            trigger-class="btn btn-ghost btn-sm"
                                            trigger-style="color:var(--danger)"
                                            consequence="This client can no longer lease the secret. Any lease it already holds stays valid until it expires." />
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
                        <x-empty-state icon="key" title="No secrets stored yet"
                                       :help="\App\Platform\Help\HelpTopic::TokenVault"
                                       body="Every API key sitting in an app’s config or environment file is one you cannot rotate centrally or take away in a hurry. Stored here, it is encrypted, granted per app, and revocable in one click."
                                       :steps="[
                                           'Store the provider’s API key below — you will not see it again afterwards.',
                                           'Grant the specific apps that may use it; nothing else can read it.',
                                           'Rotate it here when the provider issues a new one — the apps keep working, unchanged.',
                                       ]" />
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
