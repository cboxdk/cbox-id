<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app', ['title' => 'Confirm it\'s you'])] class extends Component
{
    #[Validate('required|string')]
    public string $password = '';

    public function confirm(Subjects $subjects, Sudo $sudo, CurrentUser $me): void
    {
        $this->validate();

        // Throttle re-auth just like login — a live session shouldn't grant
        // unlimited password guesses.
        $key = 'sudo|'.$me->id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('password', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! $subjects->verifyPassword($me->id(), $this->password)) {
            RateLimiter::hit($key, 60);
            $this->addError('password', 'That password is incorrect.');

            return;
        }

        RateLimiter::clear($key);
        $sudo->confirm();

        $intended = session()->pull('sudo.intended');
        $this->redirect(is_string($intended) ? $intended : route('settings'), navigate: false);
    }
}; ?>

<div>
    <h1 class="text-2xl font-semibold tracking-tight">Confirm it's you</h1>
    <p class="mt-1.5 text-sm" style="color:var(--muted)">
        This is a protected action. Re-enter your password to continue.
    </p>

    <form wire:submit="confirm" class="mt-6 space-y-4">
        <div>
            <label class="label" for="password">Password</label>
            <input wire:model="password" id="password" type="password" autocomplete="current-password" class="input" autofocus>
            @error('password') <p class="field-error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="confirm">Confirm</span>
            <span wire:loading wire:target="confirm">Confirming…</span>
        </button>
    </form>
</div>
