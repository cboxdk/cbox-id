<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Exceptions\InvalidPasswordReset;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Choose a new password'])] class extends Component
{
    public string $token = '';

    #[Validate('required|min:12|confirmed')]
    public string $password = '';

    #[Validate('required')]
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function resetPassword(PasswordReset $resets): void
    {
        $this->validate();

        try {
            $resets->reset($this->token, $this->password);
        } catch (InvalidPasswordReset) {
            $this->addError('password', 'This reset link is invalid or has expired. Request a new one.');

            return;
        }

        session()->flash('status', 'Your password has been reset — sign in with your new password.');
        $this->redirectRoute('login', navigate: false);
    }
}; ?>

<div>
    <h1 class="text-2xl font-semibold tracking-tight">Choose a new password</h1>
    <p class="mt-1.5 text-sm" style="color:var(--muted)">Pick a strong password of at least 12 characters.</p>

    <form wire:submit="resetPassword" class="mt-6 space-y-4">
        <div>
            <label for="password" class="block text-sm font-medium mb-1.5">New password</label>
            <input wire:model="password" id="password" type="password" autocomplete="new-password" autofocus
                   class="input w-full" placeholder="••••••••••••">
            @error('password') <p class="mt-1.5 text-xs" style="color:var(--danger)">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium mb-1.5">Confirm new password</label>
            <input wire:model="password_confirmation" id="password_confirmation" type="password" autocomplete="new-password"
                   class="input w-full" placeholder="••••••••••••">
        </div>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="resetPassword">
            <span wire:loading.remove wire:target="resetPassword">Reset password</span>
            <span wire:loading wire:target="resetPassword">Resetting…</span>
        </button>
    </form>

    <p class="mt-6 text-sm text-center" style="color:var(--muted)">
        <a href="{{ route('login') }}" class="font-medium underline underline-offset-2" style="color:var(--accent)">Back to sign in</a>
    </p>
</div>
