<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Platform\Contracts\OperatorMfa;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

/**
 * The operator second-factor challenge — reached only after a password verifies
 * against an operator that has a confirmed TOTP factor. The pending marker alone
 * grants no console access; the full session is established here, on success.
 */
new #[Layout('components.layouts.auth', ['title' => 'Operator verification'])] class extends Component
{
    #[Validate('required|digits:6')]
    public string $code = '';

    public string $recoveryCode = '';

    public bool $useRecovery = false;

    public function mount(OperatorAuth $auth): void
    {
        // No pending password step -> nothing to verify.
        if ($auth->pendingOperatorId() === null) {
            $this->redirectRoute('operator.login', navigate: false);
        }
    }

    public function verify(OperatorAuth $auth, OperatorMfa $mfa): void
    {
        $this->validate();

        $pending = $auth->pendingOperatorId();
        if ($pending === null) {
            $this->redirectRoute('operator.login', navigate: false);

            return;
        }

        // Throttle brute force of the 6-digit code (1M space), keyed to the pending
        // operator so it can't be ground down.
        $key = 'operator-mfa|'.$pending;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! $mfa->verifyTotp($pending, $this->code)) {
            RateLimiter::hit($key, 60);
            $this->addError('code', 'That code is incorrect or has expired.');

            return;
        }

        RateLimiter::clear($key);
        $auth->establish($pending);
        $this->redirect(route('operator.environments'), navigate: false);
    }

    public function useRecoveryCode(OperatorAuth $auth, OperatorMfa $mfa): void
    {
        $this->validate(['recoveryCode' => 'required|string|min:6|max:64']);

        $pending = $auth->pendingOperatorId();
        if ($pending === null) {
            $this->redirectRoute('operator.login', navigate: false);

            return;
        }

        // Same brute-force throttle as the TOTP path, keyed to the pending operator.
        $key = 'operator-mfa|'.$pending;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('recoveryCode', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! $mfa->verifyRecoveryCode($pending, $this->recoveryCode)) {
            RateLimiter::hit($key, 60);
            $this->addError('recoveryCode', 'That recovery code is invalid or already used.');

            return;
        }

        RateLimiter::clear($key);
        $auth->establish($pending);
        $this->redirect(route('operator.environments'), navigate: false);
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Operator verification</h1>

    @if (! $useRecovery)
        <p class="mt-2 text-sm" style="color:var(--muted)">Enter the 6-digit code from your authenticator app.</p>

        <form wire:submit="verify" class="mt-7 space-y-4">
            <div>
                <label class="label" for="code">Authentication code</label>
                <input wire:model="code" id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                       class="input input-lg mono" style="letter-spacing:0.5em;text-align:center" placeholder="000000" autofocus>
                @error('code') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="verify">Verify</span>
                <span wire:loading wire:target="verify" class="inline-flex items-center gap-2"><span class="spinner"></span> Verifying…</span>
            </button>
        </form>

        <button type="button" wire:click="$set('useRecovery', true)" class="mt-4 text-sm underline underline-offset-2" style="color:var(--accent)">
            Use a recovery code instead
        </button>
    @else
        <p class="mt-2 text-sm" style="color:var(--muted)">Enter one of the recovery codes you saved when enabling two-factor.</p>

        <form wire:submit="useRecoveryCode" class="mt-7 space-y-4">
            <div>
                <label class="label" for="recoveryCode">Recovery code</label>
                <input wire:model="recoveryCode" id="recoveryCode" autocomplete="one-time-code"
                       class="input input-lg mono" placeholder="xxxxx-xxxxx" autofocus>
                @error('recoveryCode') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="useRecoveryCode">Verify recovery code</span>
                <span wire:loading wire:target="useRecoveryCode" class="inline-flex items-center gap-2"><span class="spinner"></span> Verifying…</span>
            </button>
        </form>

        <button type="button" wire:click="$set('useRecovery', false)" class="mt-4 text-sm underline underline-offset-2" style="color:var(--accent)">
            Use your authenticator app instead
        </button>
    @endif

    <form method="POST" action="{{ route('operator.logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="text-sm underline underline-offset-2" style="color:var(--muted)">Cancel and sign out</button>
    </form>
</div>
