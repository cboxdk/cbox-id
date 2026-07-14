<?php

declare(strict_types=1);

use App\Mail\PasswordResetMail;
use Cbox\Id\Identity\Contracts\PasswordReset;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Reset password'])] class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    public bool $sent = false;

    /** Surfaced only outside production, for local development. */
    public ?string $devUrl = null;

    public function sendResetLink(PasswordReset $resets): void
    {
        $this->validate();

        // Throttle by email + IP so the endpoint can't be used to spray reset mail
        // or probe which addresses are registered.
        $key = 'pwreset:'.sha1(mb_strtolower($this->email).'|'.request()->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('email', 'Too many attempts. Please wait a few minutes and try again.');

            return;
        }
        RateLimiter::hit($key, 900);

        // request() returns null for an unknown address; we show the SAME confirmation
        // either way so the page never reveals whether an account exists.
        $token = $resets->request($this->email);

        if ($token !== null) {
            $url = route('password.reset', $token);
            Mail::to($this->email)->send(new PasswordResetMail($url));
            $this->devUrl = app()->environment('local') ? $url : null;
        }

        $this->sent = true;
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Reset your password</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">Enter your email and we'll send a reset link.</p>

    @if ($sent)
        <div role="status" aria-live="polite" class="mt-6 rounded-lg px-4 py-3 text-sm" style="background:var(--surface-2);color:var(--text)">
            If an account exists for <span class="font-medium">{{ $email }}</span>, a reset link is on its way.
            @if ($devUrl)
                <a href="{{ $devUrl }}" class="mt-2 inline-block underline underline-offset-2 mono" style="color:var(--accent);word-break:break-all">{{ $devUrl }}</a>
            @endif
        </div>
    @else
        <form wire:submit="sendResetLink" class="mt-7 space-y-4" method="post">
            <div>
                <label for="email" class="label">Email</label>
                <input wire:model="email" id="email" name="email" type="email" inputmode="email" autofocus
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       class="input input-lg" placeholder="you@company.com"
                       @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email') <p class="field-error" id="email-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="sendResetLink">
                <span wire:loading.remove wire:target="sendResetLink">Send reset link</span>
                <span wire:loading wire:target="sendResetLink" class="inline-flex items-center gap-2"><span class="spinner"></span> Sending…</span>
            </button>
        </form>
    @endif

    <p class="mt-6 text-sm text-center" style="color:var(--muted)">
        Remembered it? <a href="{{ route('login') }}" class="font-medium underline underline-offset-2" style="color:var(--accent)">Back to sign in</a>
    </p>
</div>
