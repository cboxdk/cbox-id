<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Environment control plane › Users › New. A dedicated, deep-linkable create page.
 * The user completes sign-in via an invite or magic link — no password is set here.
 * On success we route straight to the new user's detail page.
 */
new #[Layout('components.layouts.environment', ['title' => 'New user'])] class extends Component
{
    public string $email = '';

    public string $name = '';

    public function create(Subjects $subjects): mixed
    {
        $this->validate([
            'email' => ['required', 'email', 'max:190'],
            'name' => ['nullable', 'string', 'max:190'],
        ]);

        if ($subjects->findByEmail($this->email) !== null) {
            $this->addError('email', 'A user with that email already exists in this environment.');

            return null;
        }

        $subject = $subjects->create(trim($this->email), trim($this->name) !== '' ? trim($this->name) : null);
        $user = User::query()->where('email', $this->email)->first();

        session()->flash('status', 'User created.');

        return $this->redirectRoute('environment.users.show', ['user' => $user?->id ?? $subject->id], navigate: true);
    }
}; ?>

<div>
    <a href="{{ route('environment.users') }}" class="text-sm inline-flex items-center gap-1" style="color:var(--muted)"><x-icon name="chevron" class="w-3.5 h-3.5 rotate-180" /> Users</a>
    <h1 class="mt-2 font-semibold tracking-tight" style="font-size:1.5rem">New user</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">They complete sign-in via an invite or magic link — no password is set here.</p>

    <form wire:submit="create" class="mt-6 max-w-xl rounded-xl border p-5 space-y-4" style="border-color:var(--border)">
        <div>
            <label class="label" for="email">Email</label>
            <input wire:model="email" id="email" type="email" class="input" placeholder="user@example.com" autofocus>
            @error('email') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="name">Name <span style="color:var(--faint)">(optional)</span></label>
            <input wire:model="name" id="name" type="text" class="input" placeholder="Full name">
            @error('name') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-center gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="create">Create user</button>
            <a href="{{ route('environment.users') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</div>
