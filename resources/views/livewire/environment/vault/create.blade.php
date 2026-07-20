<?php

declare(strict_types=1);

use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\TokenVault\Contracts\SecretVault;
use Cbox\Id\TokenVault\ValueObjects\VaultOwner;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * Environment control plane › Stored tokens › New. A dedicated, deep-linkable create
 * page for a downstream credential. The value is handled in the clear this one time
 * and sealed on store (Crypto SecretBox) — it is NEVER echoed back afterwards. A
 * secret may optionally be scoped to one of the environment's organizations; both the
 * secret and its scope stay within this environment (BelongsToEnvironment). On success
 * we route to the new secret's detail page.
 */
new #[Layout('components.layouts.environment', ['title' => 'New stored token'])] class extends Component
{
    #[Validate('required|string|max:190')]
    public string $name = '';

    #[Validate('required|string|max:190')]
    public string $provider = '';

    #[Validate('required|string')]
    public string $secret = '';

    /** The organization the new secret is scoped to, or '' for environment-wide. */
    public string $ownerId = '';

    public function store(SecretVault $vault): mixed
    {
        $this->validateOnly('name');
        $this->validateOnly('provider');
        $this->validateOnly('secret');

        if ($this->ownerId !== '' && Organization::query()->whereKey($this->ownerId)->doesntExist()) {
            $this->addError('ownerId', 'That organization is not in this environment.');

            return null;
        }

        // Scope to an organization only when one is chosen; otherwise the secret is
        // environment-wide. Both stay within this environment (BelongsToEnvironment).
        $model = $this->ownerId !== ''
            ? $vault->store($this->name, $this->provider, $this->secret, VaultOwner::organization($this->ownerId))
            : $vault->store($this->name, $this->provider, $this->secret);

        session()->flash('status', 'Secret sealed and stored — its value is never shown again.');

        return $this->redirectRoute('environment.vault.show', ['secret' => $model->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'organizations' => Organization::query()->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <a href="{{ route('environment.vault') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Stored tokens</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New stored token</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">A downstream API key your AI agents present to a provider. It is sealed on store and brokered only to explicitly granted clients.</p>

    <form wire:submit="store" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Name</label>
                <input wire:model="name" id="name" type="text" class="input" placeholder="openai-prod" autofocus>
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="provider">Provider</label>
                <input wire:model="provider" id="provider" type="text" class="input" placeholder="openai">
                @error('provider') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="owner">Scope</label>
                <select wire:model="ownerId" id="owner" class="select">
                    <option value="">Environment-wide</option>
                    @foreach ($organizations as $org)
                        <option value="{{ $org->id }}">{{ $org->name }}</option>
                    @endforeach
                </select>
                @error('ownerId') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="secret">Secret value</label>
                <input wire:model="secret" id="secret" type="password" class="input mono" placeholder="sk-live-…" autocomplete="off">
                @error('secret') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Write-only handling: the value is handled in the clear this one time and
             sealed on store — it is never echoed back, so warn before submitting. --}}
        <div class="rounded-xl border p-5" style="border-color:color-mix(in oklch,var(--warning) 35%,transparent);background:var(--warning-soft);color:var(--warning)">
            <p class="text-sm font-medium">This is the only time the value is handled in the clear.</p>
            <p class="mt-1 text-xs">It is sealed on store and never shown again — keep your own copy if you need one.</p>
        </div>

        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="store">Seal &amp; store</button>
            <a href="{{ route('environment.vault') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
