<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Enums\AttemptOutcome;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace sign-in — the account-member (buyer) plane. One root login for the
 * whole account, from which the member reaches every environment they own. This
 * is the answer to "I forgot my subdomain / I have several": sign in here, not on
 * an environment's own domain.
 */
new #[Layout('components.layouts.auth', ['title' => 'Workspace sign in'])] class extends Component
{
    public string $email = '';

    public string $password = '';

    public function mount(AccountAuth $auth)
    {
        if ($auth->check()) {
            return redirect()->intended(route('workspace.home'));
        }
    }

    public function login(AccountAuth $auth): void
    {
        $this->validate([
            'email' => 'required|email|max:190',
            'password' => 'required|string',
        ]);

        // Throttle brute force, keyed on email + IP — this plane administers whole
        // IdPs, so it gets the same discipline as the operator console.
        $key = 'workspace-login|'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        $result = $auth->attempt(request(), $this->email, $this->password);

        if ($result === AttemptOutcome::Invalid) {
            RateLimiter::hit($key, 60);
            // Neutral message — never reveal whether the email is a real member.
            $this->addError('email', 'Those credentials do not match a workspace.');

            return;
        }

        RateLimiter::clear($key);

        // A confirmed second factor holds the member at the challenge — no full
        // session exists yet, so leave url.intended in place for the MFA step to honor.
        // Otherwise the session is established: return them to where they were headed
        // (e.g. the /open/{env} handoff mint that bounced them here), else the home.
        if ($result === AttemptOutcome::Mfa) {
            $this->redirect(route('workspace.login.mfa'), navigate: false);

            return;
        }

        $this->redirect(session()->pull('url.intended', route('workspace.home')), navigate: false);
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Sign in to your workspace</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">Manage your environments, users, and sign-in — all from one place.</p>

    @if (session('status'))
        <p class="mt-4 text-sm rounded-lg px-3 py-2" style="background:var(--surface-2);color:var(--muted)">{{ session('status') }}</p>
    @endif

    <form wire:submit="login" class="mt-7 space-y-4">
        <div>
            <label class="label" for="email">Work email</label>
            <input wire:model="email" id="email" name="email" type="email" autocomplete="username" autocapitalize="none" spellcheck="false" class="input input-lg" placeholder="you@yourco.example" autofocus>
            @error('email') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <div class="flex items-center justify-between">
                <label class="label" for="password">Password</label>
                <a href="{{ route('workspace.password.request') }}" class="text-xs underline underline-offset-2" style="color:var(--accent)">Forgot password?</a>
            </div>
            <input wire:model="password" id="password" name="password" type="password" autocomplete="current-password" class="input input-lg" placeholder="••••••••••••">
            @error('password') <p class="field-error" role="alert">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="login">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login" class="inline-flex items-center gap-2"><span class="spinner"></span> Signing in…</span>
        </button>
    </form>

    <div class="mt-5 flex items-center gap-3 text-xs" style="color:var(--faint)">
        <span class="flex-1" style="height:1px;background:var(--border)"></span> or <span class="flex-1" style="height:1px;background:var(--border)"></span>
    </div>
    <button type="button" class="btn btn-ghost btn-lg w-full mt-5"
            data-passkey-login data-passkey-base="/workspace/passkeys" data-passkey-feedback="pk-login-feedback">
        <x-icon name="key" class="w-4 h-4" /> Sign in with a passkey
    </button>
    <p id="pk-login-feedback" class="mt-2 text-xs text-center" aria-live="polite"></p>

    <p class="mt-8 text-sm text-center" style="color:var(--muted)">
        Don't have a workspace yet? <a href="{{ route('signup') }}" class="font-medium underline underline-offset-2" style="color:var(--accent)">Create your identity platform</a>
    </p>
</div>
