<?php

declare(strict_types=1);

use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Organization\Models\Organization;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Directories › New. A dedicated, deep-linkable create
 * page for a SCIM (push) directory. Registration mints a bearer token — the customer's
 * IdP authenticates every SCIM request with it — that is returned in plaintext exactly
 * once and stored only as a SHA-256 hash. We hand it off to the detail page (flashed,
 * so it survives the single redirect and is then gone) where it is revealed once.
 *
 * The directory belongs to an organization inside this environment; the org selector
 * is validated against the env-scoped Organization set (deny-by-default — a foreign id
 * never resolves).
 */
new #[Layout('components.layouts.environment', ['title' => 'New directory'])] class extends Component
{
    public string $name = '';

    public string $organizationId = '';

    public function create(Directories $directories): mixed
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'organizationId' => ['required', 'string'],
        ]);

        if (Organization::query()->whereKey($this->organizationId)->doesntExist()) {
            $this->addError('organizationId', 'That organization is not in this environment.');

            return null;
        }

        $registered = $directories->register($this->organizationId, trim($this->name));

        // Reveal the plaintext bearer token exactly once, on the detail page. Only its
        // hash is persisted, so it can never be retrieved again after this hand-off.
        session()->flash('newToken', $registered->token);
        session()->flash('newTokenName', $registered->directory->name);
        $this->dispatch('toast', message: 'Directory registered — copy the bearer token below, it is shown only once.');

        return $this->redirectRoute('environment.directories.show', ['directory' => $registered->directory->id], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return ['organizations' => Organization::query()->orderBy('name')->pluck('name', 'id')];
    }
}; ?>

<div>
    <a href="{{ route('environment.directories') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Directories</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New directory</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Registers a SCIM (push) directory. It issues a bearer token on creation — shown once — that the customer's identity provider uses to authenticate.</p>

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="name">Directory name</label>
            <input wire:model="name" id="name" type="text" class="input" placeholder="Acme Okta SCIM" autofocus>
            @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="organizationId">Organization</label>
            <select wire:model="organizationId" id="organizationId" class="select">
                <option value="">Choose an organization…</option>
                @foreach ($organizations as $orgId => $orgName)
                    <option value="{{ $orgId }}">{{ $orgName }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs" style="color:var(--faint)">The tenant whose users this directory provisions.</p>
            @error('organizationId') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Register directory</button>
            <a href="{{ route('environment.directories') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
