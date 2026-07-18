<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Identity\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Users. Lists, searches, creates and deactivates the
 * end-user identities across the whole environment (host-resolved env scope). Writes
 * go through the {@see Subjects} service so a host with its own subject resolver stays
 * authoritative; deactivation is a soft disable, never a hard delete.
 */
new #[Layout('components.layouts.environment', ['title' => 'Users'])] class extends Component
{
    public string $search = '';

    public string $email = '';

    public string $name = '';

    public function createUser(Subjects $subjects): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:190'],
        ]);

        if ($subjects->findByEmail($this->email) !== null) {
            $this->addError('email', 'A user with that email already exists in this environment.');

            return;
        }

        $subjects->create(trim($this->email), trim($this->name) !== '' ? trim($this->name) : null);

        $this->reset('email', 'name');
        session()->flash('status', 'User created.');
    }

    public function deactivate(string $id, Subjects $subjects): void
    {
        // Only a user that actually resolves within THIS environment (hard scope).
        if (User::query()->whereKey($id)->exists()) {
            $subjects->deactivate($id);
            session()->flash('status', 'User deactivated.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $query = User::query()->orderBy('email')->limit(100);

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(fn ($q) => $q->where('email', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%"));
        }

        return ['users' => $query->get()];
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Users</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Every end-user identity in this environment.</p>

    <div class="mt-6">
        <input wire:model.live.debounce.300ms="search" type="search" class="input" style="max-width:24rem" placeholder="Search by email or name">
    </div>

    <div class="mt-4 rounded-xl border overflow-hidden" style="border-color:var(--border)">
        @forelse ($users as $user)
            <div class="flex items-center gap-3 p-4 {{ ! $loop->last ? 'border-b' : '' }}" style="border-color:var(--border)">
                <div class="min-w-0 flex-1">
                    <span class="font-medium truncate">{{ $user->name ?? $user->email }}</span>
                    <p class="text-sm truncate mono" style="color:var(--muted)">{{ $user->email }}</p>
                </div>
                <span class="text-xs rounded-full px-2 py-0.5" style="background:var(--surface-2);color:var(--muted)">{{ $user->status->value }}</span>
                @if ($user->status === UserStatus::Active)
                    <button type="button" class="btn btn-ghost btn-sm shrink-0" style="color:var(--destructive)"
                            wire:click="deactivate('{{ $user->id }}')"
                            wire:confirm="Deactivate this user? They can no longer sign in.">Deactivate</button>
                @endif
            </div>
        @empty
            <p class="p-4 text-sm" style="color:var(--muted)">No users match.</p>
        @endforelse
    </div>

    <div class="mt-6 rounded-xl border p-5" style="border-color:var(--border)">
        <p class="text-sm font-medium">Create a user</p>
        <p class="mt-1 text-sm" style="color:var(--muted)">They complete sign-in via an invite or magic link — no password is set here.</p>
        <form wire:submit="createUser" class="mt-4 grid sm:grid-cols-[1fr_1fr_auto] gap-2 items-start">
            <div>
                <input wire:model="email" type="email" class="input" placeholder="user@example.com" aria-label="Email">
                @error('email') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <input wire:model="name" type="text" class="input" placeholder="Full name (optional)" aria-label="Name">
                @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary shrink-0" wire:loading.attr="disabled" wire:target="createUser">Create</button>
        </form>
    </div>
</div>
