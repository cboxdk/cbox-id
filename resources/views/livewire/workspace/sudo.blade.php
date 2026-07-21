<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\WorkspaceSudo;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.workspace', ['title' => 'Confirm it\'s you'])] class extends Component
{
    #[Validate('required|string')]
    public string $password = '';

    public function confirm(AccountAuth $auth, AccountMembers $members, WorkspaceSudo $sudo): void
    {
        $this->validate();

        $memberId = $auth->id();

        if ($memberId === null) {
            $this->redirectRoute('workspace.login', navigate: false);

            return;
        }

        // Throttle re-auth just like login — a live session shouldn't grant
        // unlimited password guesses.
        $key = 'workspace-sudo|'.$memberId;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('password', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! $members->verifyPassword($memberId, $this->password)) {
            RateLimiter::hit($key, 60);
            $this->addError('password', 'That password is incorrect.');

            return;
        }

        RateLimiter::clear($key);
        $sudo->confirm();

        $intended = session()->pull('workspace.sudo.intended');
        $this->redirect(is_string($intended) ? $intended : route('workspace.security'), navigate: false);
    }
}; ?>

<div class="max-w-md">
    <div class="mb-6">
        <span class="grid place-items-center rounded-full mb-4" style="width:2.5rem;height:2.5rem;background:var(--accent-soft);color:var(--accent)">
            <x-icon name="shield" class="w-5 h-5" />
        </span>
        <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Confirm it's you</h1>
        <p class="mt-2 text-sm" style="color:var(--muted)">
            This is a protected action. Re-enter your password to continue.
        </p>
    </div>

    <div class="card p-5">
        <form wire:submit="confirm" class="space-y-4">
            <div>
                <label for="sudo-password" class="block text-sm font-medium mb-1.5">Password</label>
                <input
                    id="sudo-password"
                    type="password"
                    wire:model="password"
                    autocomplete="current-password"
                    autofocus
                    class="input w-full"
                    @error('password') aria-invalid="true" @enderror
                />
                @error('password')
                    <p class="mt-1.5 text-sm" style="color:var(--danger)">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="confirm">
                <span wire:loading.remove wire:target="confirm">Confirm</span>
                <span wire:loading wire:target="confirm">Confirming…</span>
            </button>
        </form>
    </div>
</div>
