<?php

declare(strict_types=1);

use App\Mail\MagicLinkMail;
use App\Platform\PlatformAuth;
use App\Platform\RiskGuard;
use App\Platform\SignupPolicy;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Sign in'])] class extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $magicSent = false;

    public ?string $magicUrl = null;

    public ?string $pendingLink = null;

    /**
     * Branded, per-organization login (/o/{slug}/login) themes the page with the
     * org's colour, logo and name.
     */
    public function mount(?string $slug = null): void
    {
        $this->pendingLink = app(PlatformAuth::class)->pendingLinkLabel();

        if ($slug === null) {
            return;
        }

        $org = app(Organizations::class)->bySlug($slug);

        if ($org !== null) {
            View::share('cboxBrand', [
                'name' => $org->name,
                'color' => is_string($org->settings['brand_color'] ?? null) ? $org->settings['brand_color'] : null,
                'logo' => is_string($org->settings['brand_logo_url'] ?? null) ? $org->settings['brand_logo_url'] : null,
            ]);
        }
    }

    public function login(PlatformAuth $auth, RiskGuard $risk): void
    {
        $this->validate();

        $key = $this->throttleKey('login');

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        // Risk-score the attempt (credential-stuffing / bot velocity, IP reputation,
        // Tor). Logged for review; hard-blocks a Reject only under enforcement.
        if ($risk->shouldBlock($risk->assess(request(), 'login', $this->email))) {
            $this->addError('email', 'We could not process this request. Please try again later.');

            return;
        }

        $result = $auth->attemptPassword(request(), $this->email, $this->password);

        if ($result === 'invalid') {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'Those credentials do not match our records.');

            return;
        }

        RateLimiter::clear($key);
        $this->redirectRoute($result === 'mfa' ? 'mfa' : 'dashboard', navigate: false);
    }

    public function sendMagicLink(MagicLink $links, Subjects $subjects, SignupPolicy $signup): void
    {
        $this->validateOnly('email');

        $key = $this->throttleKey('magic');

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('email', 'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        RateLimiter::hit($key, 120);

        // Redeeming a magic link provisions the account on first login
        // (findByEmail ?? create), so an unqualified link is a signup bypass:
        // under invite_only/closed it would mint an account for any email. Only
        // issue a link when signup is open OR the address already has an account.
        // The confirmation below shows either way, so the page never reveals
        // whether an account exists (mirrors the password-reset pattern).
        if ($signup->isOpen() || $subjects->findByEmail($this->email) !== null) {
            $token = $links->request($this->email);
            $url = route('magic.redeem', $token);

            Mail::to($this->email)->send(new MagicLinkMail($url));

            // Also surface the link directly in local dev (never on staging/prod).
            $this->magicUrl = app()->environment('local') ? $url : null;
        }

        $this->magicSent = true;
    }

    private function throttleKey(string $action): string
    {
        return $action.'|'.Str::lower($this->email).'|'.request()->ip();
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Sign in</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">Welcome back. Access your organization's identity console.</p>

    @if ($pendingLink)
        <div class="mt-5 rounded-lg px-3.5 py-3 text-sm" style="background:var(--accent-soft);color:var(--accent);border:1px solid color-mix(in srgb,var(--accent) 30%,transparent)">
            <b>Connect your {{ $pendingLink }} account.</b> Sign in below with your existing method and we'll link it to your account.
        </div>
    @endif

    @if (session('status'))
        <div role="status" aria-live="polite" class="mt-5 rounded-lg px-3.5 py-2.5 text-sm inline-flex items-center gap-2" style="background:var(--success-soft);color:var(--success)">
            <x-icon name="check" class="w-4 h-4" /> {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div role="alert" class="mt-5 rounded-lg px-3.5 py-2.5 text-sm" style="background:var(--danger-soft);color:var(--danger)">
            {{ session('error') }}
        </div>
    @endif

    @if ($magicSent)
        <div role="status" aria-live="polite" class="mt-5 rounded-lg px-3.5 py-3 text-sm card" style="padding:0.85rem 1rem">
            <p class="font-medium">Check your inbox</p>
            <p class="mt-1" style="color:var(--muted)">We sent a one-time sign-in link to <b>{{ $email }}</b>.</p>
            @if ($magicUrl)
                <a href="{{ $magicUrl }}" class="mt-2 inline-block text-sm underline underline-offset-2 mono" style="color:var(--accent);word-break:break-all">{{ $magicUrl }}</a>
                <p class="mt-1 text-xs" style="color:var(--faint)">Shown because email isn't configured in this environment.</p>
            @endif
        </div>
    @endif

    {{-- name + autocomplete so password managers (1Password, iCloud Keychain, browsers) recognise and fill the form. --}}
    <form wire:submit="login" class="mt-7 space-y-4" method="post" action="{{ route('login') }}">
        <div>
            <label class="label" for="email">Email</label>
            <input wire:model="email" id="email" name="email" type="email" inputmode="email"
                   autocomplete="username" autocapitalize="none" spellcheck="false"
                   class="input input-lg" placeholder="you@company.com" autofocus
                   @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
            @error('email') <p class="field-error" id="email-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div>
            <div class="flex items-center justify-between mb-1.5">
                <label class="label" for="password" style="margin-bottom:0">Password</label>
                <a href="{{ route('password.request') }}" class="text-xs font-medium underline underline-offset-2" style="color:var(--accent)">Forgot password?</a>
            </div>
            <input wire:model="password" id="password" name="password" type="password"
                   autocomplete="current-password" class="input input-lg" placeholder="••••••••••••"
                   @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            @error('password') <p class="field-error" id="password-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login" class="inline-flex items-center gap-2">
                <span class="spinner"></span> Signing in…
            </span>
        </button>
    </form>

    <div class="divider my-6">OR</div>

    <x-social-buttons class="mb-2.5" />

    <div class="space-y-2.5">
        <button type="button" wire:click="sendMagicLink" class="btn btn-ghost btn-lg w-full" wire:loading.attr="disabled" wire:target="sendMagicLink">
            <x-icon name="magic" class="w-4 h-4" /> Email me a magic link
        </button>
        <button type="button" data-passkey-login data-passkey-feedback="passkey-msg" data-passkey-only class="btn btn-ghost btn-lg w-full">
            <x-icon name="key" class="w-4 h-4" /> Sign in with a passkey
        </button>
        <p id="passkey-msg" role="status" aria-live="polite" class="text-xs text-center" style="min-height:1rem"></p>
    </div>

    @if (app(\App\Platform\SignupPolicy::class)->isOpen())
        <p class="mt-8 text-sm text-center" style="color:var(--muted)">
            New organization? <a href="{{ route('signup') }}" class="font-medium underline underline-offset-2" style="color:var(--accent)">Create one</a>
        </p>
    @endif
</div>
