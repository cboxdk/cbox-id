<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Organizations. Lists and creates the organizations
 * (the environment's own tenants) — the env-scoped, account-member-driven equivalent
 * of the account API's organizations endpoint. Reads query the hard env-scoped model;
 * create delegates to the {@see Organizations} service.
 */
new #[Layout('components.layouts.environment', ['title' => 'Organizations'])] class extends Component
{
    public string $name = '';

    public string $slug = '';

    public function create(Organizations $organizations): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'slug' => ['required', 'string', 'max:190', 'alpha_dash'],
        ]);

        if ($organizations->bySlug($this->slug) !== null) {
            $this->addError('slug', 'That slug is already in use in this environment.');

            return;
        }

        $organizations->create(new NewOrganization(name: trim($this->name), slug: trim($this->slug)));

        $this->reset('name', 'slug');
        session()->flash('status', 'Organization created.');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return ['organizations' => Organization::query()->orderBy('name')->limit(100)->get()];
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Organizations</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">The tenants inside this environment. Each has its own users, roles, and SSO.</p>

    <div class="mt-6 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($organizations as $org)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $org->name }}</span>
                    <p class="text-sm truncate mono" style="color:var(--muted)">{{ $org->slug }}</p>
                </div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $org->status->value }}</span>
            </div>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No organizations yet.</p>
        @endforelse
    </div>

    <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Create an organization</p>
        <form wire:submit="create" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-start">
            <div>
                <input wire:model="name" type="text" class="input" placeholder="Acme Inc" aria-label="Organization name">
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <input wire:model="slug" type="text" class="input mono" placeholder="acme" aria-label="Slug">
                @error('slug') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="create">Create</button>
        </form>
    </div>
</div>
