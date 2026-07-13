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
            $this->devUrl = app()->environment('production') ? null : $url;
        }

        $this->sent = true;
    }
}; ?>

<div>
    <h1 class="text-2xl font-semibold tracking-tight">Reset your password</h1>
    <p class="mt-1.5 text-sm" style="color:var(--muted)">Enter your email and we'll send a reset link.</p>

    @if ($sent)
        <div class="mt-6 rounded-lg px-4 py-3 text-sm" style="background:var(--surface-2);color:var(--text)">
            If an account exists for <span class="font-medium">{{ $email }}</span>, a reset link is on its way.
            @if ($devUrl)
                <a href="{{ $devUrl }}" class="mt-2 inline-block underline underline-offset-2 mono" style="color:var(--accent);word-break:break-all">{{ $devUrl }}</a>
            @endif
        </div>
    @else
        <form wire:submit="sendResetLink" class="mt-6 space-y-4">
            <div>
                <label for="email" class="block text-sm font-medium mb-1.5">Email</label>
                <input wire:model="email" id="email" type="email" autocomplete="email" autofocus
                       class="input w-full" placeholder="you@company.com">
                @error('email') <p class="mt-1.5 text-xs" style="color:var(--danger)">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="sendResetLink">
                <span wire:loading.remove wire:target="sendResetLink">Send reset link</span>
                <span wire:loading wire:target="sendResetLink">Sending…</span>
            </button>
        </form>
    @endif

    <p class="mt-6 text-sm text-center" style="color:var(--muted)">
        Remembered it? <a href="{{ route('login') }}" class="font-medium underline underline-offset-2" style="color:var(--accent)">Back to sign in</a>
    </p>
</div>
