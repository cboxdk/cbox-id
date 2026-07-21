<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\PasswordReset;
use Cbox\Id\Identity\Exceptions\InvalidPasswordReset;
use Livewire\Attributes\Layout;
use App\Rules\NotBreached;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Choose a new password'])] class extends Component
{
    public string $token = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function resetPassword(PasswordReset $resets): void
    {
        // NotBreached belongs here more than anywhere: this is the flow an attacker with
        // a stolen reset token uses, and the one where a user is most likely to reach for
        // a password they have used before. Every OTHER password-setting path screened
        // it — signup, invite acceptance, the workspace reset — and this one did not.
        $this->validate([
            'password' => ['required', 'string', 'min:12', 'max:200', 'confirmed', new NotBreached],
            'password_confirmation' => ['required'],
        ]);

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
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Choose a new password</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">Pick a strong password of at least 12 characters.</p>

    {{-- A hidden username field + autocomplete=new-password lets password managers
         associate and update the saved credential. --}}
    <form wire:submit="resetPassword" class="mt-7 space-y-4" method="post">
        <input type="text" name="username" autocomplete="username" class="hidden" tabindex="-1" aria-hidden="true">

        <div x-data="{ pw: '' }">
            <label for="password" class="label">New password</label>
            <input wire:model="password" x-on:input="pw = $event.target.value"
                   id="password" name="password" type="password" autofocus
                   autocomplete="new-password" minlength="12" passwordrules="minlength: 12; allowed: ascii-printable;"
                   class="input input-lg" placeholder="At least 12 characters"
                   aria-describedby="password-policy @error('password') password-error @enderror"
                   @error('password') aria-invalid="true" @enderror>
            <div id="password-policy" class="mt-2 flex items-center gap-1.5 text-xs" style="color:var(--faint)">
                <x-icon name="check" class="w-3.5 h-3.5" x-bind:style="pw.length >= 12 ? 'color:var(--success)' : ''" />
                <span x-bind:style="pw.length >= 12 ? 'color:var(--success)' : ''">At least 12 characters</span>
            </div>
            @error('password') <p class="field-error" id="password-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="password_confirmation" class="label">Confirm new password</label>
            <input wire:model="password_confirmation" id="password_confirmation" name="password_confirmation" type="password"
                   autocomplete="new-password" class="input input-lg" placeholder="Re-enter your new password">
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="resetPassword">
            <span wire:loading.remove wire:target="resetPassword">Reset password</span>
            <span wire:loading wire:target="resetPassword" class="inline-flex items-center gap-2"><span class="spinner"></span> Resetting…</span>
        </button>
    </form>

    <p class="mt-6 text-sm text-center" style="color:var(--muted)">
        <a href="{{ route('login') }}" class="font-medium underline underline-offset-2" style="color:var(--accent)">Back to sign in</a>
    </p>
</div>
