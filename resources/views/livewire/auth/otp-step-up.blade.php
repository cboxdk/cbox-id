<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\SamlSsoHandoff;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Additional verification'])] class extends Component
{
    #[Validate('required|digits:6')]
    public string $code = '';

    public string $maskedEmail = '';

    public function mount(PlatformAuth $auth): void
    {
        $pending = $auth->pendingOtpStepUp(request());

        // No pending step-up -> nothing to verify.
        if ($pending === null) {
            $this->redirectRoute('login', navigate: false);

            return;
        }

        $this->maskedEmail = $this->mask($pending['email']);
    }

    public function verify(PlatformAuth $auth): void
    {
        $this->validate();

        $pending = $auth->pendingOtpStepUp(request());

        if ($pending === null) {
            $this->redirectRoute('login', navigate: false);

            return;
        }

        // Throttle brute force of the 6-digit code, keyed to the pending subject.
        $key = 'otp-step-up|'.$pending['subject'];

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! $auth->completeOtpStepUp(request(), $this->code)) {
            RateLimiter::hit($key, 60);
            $this->addError('code', 'That code is incorrect or has expired.');

            return;
        }

        RateLimiter::clear($key);
        $this->redirect(app(SamlSsoHandoff::class)->resumeUrl() ?? route('dashboard'), navigate: false);
    }

    private function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $shown = mb_substr($local, 0, 1);
        $masked = $shown.str_repeat('•', max(1, mb_strlen($local) - 1));

        return $domain === '' ? $masked : $masked.'@'.$domain;
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Additional verification</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">
        This sign-in looked unusual, so we emailed a one-time code to
        <b>{{ $maskedEmail }}</b>. Enter it to continue.
    </p>

    <form wire:submit="verify" class="mt-7 space-y-4">
        <div>
            <label class="label" for="code">Verification code</label>
            <input wire:model="code" id="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                   class="input input-lg mono" style="letter-spacing:0.5em;text-align:center" placeholder="000000" autofocus>
            @error('code') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="verify">Verify</span>
            <span wire:loading wire:target="verify" class="inline-flex items-center gap-2"><span class="spinner"></span> Verifying…</span>
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6">
        @csrf
        <button type="submit" class="text-sm underline underline-offset-2" style="color:var(--muted)">Cancel and sign out</button>
    </form>
</div>
