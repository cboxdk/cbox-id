<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Two-factor verification'])] class extends Component
{
    #[Validate('required|digits:6')]
    public string $code = '';

    public string $recoveryCode = '';

    public bool $useRecovery = false;

    public function mount(PlatformAuth $auth): void
    {
        // No pending password step -> nothing to verify.
        if ($auth->pendingMfaSubject(request()) === null) {
            $this->redirectRoute('login', navigate: false);
        }
    }

    public function useRecoveryCode(PlatformAuth $auth): void
    {
        $this->validate(['recoveryCode' => 'required|string|min:6|max:64']);

        // Same brute-force throttle as the TOTP path, keyed to the pending subject.
        $key = 'mfa|'.($auth->pendingMfaSubject(request()) ?? request()->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('recoveryCode', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! $auth->completeMfaWithRecoveryCode(request(), $this->recoveryCode)) {
            RateLimiter::hit($key, 60);
            $this->addError('recoveryCode', 'That recovery code is invalid or already used.');

            return;
        }

        RateLimiter::clear($key);
        $this->redirectRoute('dashboard', navigate: false);
    }

    public function verify(PlatformAuth $auth): void
    {
        $this->validate();

        // Throttle brute force of the 6-digit code (1M space) — keyed to the
        // pending subject so an attacker can't grind it.
        $key = 'mfa|'.($auth->pendingMfaSubject(request()) ?? request()->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! $auth->completeMfa(request(), $this->code)) {
            RateLimiter::hit($key, 60);
            $this->addError('code', 'That code is incorrect or has expired.');

            return;
        }

        RateLimiter::clear($key);
        $this->redirectRoute('dashboard', navigate: false);
    }
}; ?>

<div>
    <h1 class="text-2xl font-semibold tracking-tight">Two-factor verification</h1>

    @if (! $useRecovery)
        <p class="mt-1.5 text-sm" style="color:var(--muted)">Enter the 6-digit code from your authenticator app.</p>

        <form wire:submit="verify" class="mt-6 space-y-4">
            <div>
                <label class="label" for="code">Authentication code</label>
                <input wire:model="code" id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                       class="input mono" style="letter-spacing:0.5em;font-size:1.1rem;text-align:center" placeholder="000000" autofocus>
                @error('code') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="verify">Verify</span>
                <span wire:loading wire:target="verify">Verifying…</span>
            </button>
        </form>

        <button type="button" wire:click="$set('useRecovery', true)" class="mt-4 text-sm underline underline-offset-2" style="color:var(--accent)">
            Use a recovery code instead
        </button>
    @else
        <p class="mt-1.5 text-sm" style="color:var(--muted)">Enter one of the recovery codes you saved when enabling two-factor.</p>

        <form wire:submit="useRecoveryCode" class="mt-6 space-y-4">
            <div>
                <label class="label" for="recoveryCode">Recovery code</label>
                <input wire:model="recoveryCode" id="recoveryCode" autocomplete="one-time-code"
                       class="input mono" placeholder="xxxxx-xxxxx" autofocus>
                @error('recoveryCode') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="useRecoveryCode">Verify recovery code</span>
                <span wire:loading wire:target="useRecoveryCode">Verifying…</span>
            </button>
        </form>

        <button type="button" wire:click="$set('useRecovery', false)" class="mt-4 text-sm underline underline-offset-2" style="color:var(--accent)">
            Use your authenticator app instead
        </button>
    @endif

    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="text-sm underline underline-offset-2" style="color:var(--muted)">Cancel and sign out</button>
    </form>
</div>
