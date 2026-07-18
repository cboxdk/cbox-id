<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * "Sign in as admin" — the tenant subdomain's ADMIN door. It authenticates against
 * the ACCOUNT layer (an account member), NOT the environment's subjects: the admin
 * of a tenant is an account-layer identity. On success it establishes an
 * environment-admin session bound to THIS host's environment. The end-user sign-in
 * (for the tenant's own apps) is a separate door — no layer confusion.
 *
 * Two steps in one component (server-held pending id, no session marker): password,
 * then TOTP/recovery when the member has a confirmed second factor — never weaker
 * than the account login.
 */
new #[Layout('components.layouts.auth', ['title' => 'Admin sign in'])] class extends Component
{
    public string $email = '';

    public string $password = '';

    public string $code = '';

    /** 'password' → 'mfa'. */
    public string $step = 'password';

    public string $pendingMemberId = '';

    public function authenticate(AccountMembers $members, AccountMemberMfa $mfa, EnvironmentContext $environments, EnvironmentAdminAuth $auth): void
    {
        $this->validate(['email' => 'required|email', 'password' => 'required|string']);

        $key = 'admin-login|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        $member = $members->findByEmail($this->email);
        $ok = $member !== null && $members->verifyPassword($member->id, $this->password);

        // Constant-cost miss path — no enumeration timing oracle.
        if ($member === null) {
            $members->verifyPassword('', $this->password);
        }

        // Fail identically for wrong credentials AND for a valid member with no access
        // to THIS environment — never reveal which.
        $hostEnv = $environments->current()?->environmentKey();
        $hasAccess = $ok && $member !== null && $hostEnv !== null
            && in_array($hostEnv, $members->accessibleEnvironmentIds($member), true);

        if (! $ok || ! $hasAccess || $member === null) {
            RateLimiter::hit($key);
            $this->addError('email', 'Those credentials do not grant admin access to this environment.');

            return;
        }

        if ($mfa->hasConfirmedTotp($member->id)) {
            $this->pendingMemberId = $member->id;
            $this->step = 'mfa';

            return;
        }

        $this->establish($auth, $member->id, $hostEnv);
    }

    public function verifyMfa(AccountMemberMfa $mfa, EnvironmentContext $environments, EnvironmentAdminAuth $auth): void
    {
        $this->validate(['code' => 'required|string']);

        if ($this->pendingMemberId === '') {
            $this->step = 'password';

            return;
        }

        $key = 'admin-mfa|'.$this->pendingMemberId.'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        $code = trim($this->code);
        $verified = str_contains($code, '-')
            ? $mfa->verifyRecoveryCode($this->pendingMemberId, $code)
            : $mfa->verifyTotp($this->pendingMemberId, $code);

        if (! $verified) {
            RateLimiter::hit($key);
            $this->addError('code', 'That code is not valid.');

            return;
        }

        $hostEnv = $environments->current()?->environmentKey();
        if ($hostEnv === null) {
            $this->addError('code', 'Environment could not be resolved.');

            return;
        }

        $this->establish($auth, $this->pendingMemberId, $hostEnv);
    }

    private function establish(EnvironmentAdminAuth $auth, string $memberId, string $environmentId): void
    {
        $auth->establish($memberId, $environmentId);
        $this->redirect(session()->pull('url.intended', route('environment.home')), navigate: false);
    }
}; ?>

<div class="mx-auto w-full" style="max-width:22rem">
    <h1 class="font-semibold tracking-tight" style="font-size:1.5rem">Sign in as admin</h1>
    <p class="mt-1 text-sm" style="color:var(--muted)">Administer this environment with your Cbox&nbsp;ID account. This is separate from your users' sign-in.</p>

    @if ($step === 'password')
        <form wire:submit="authenticate" class="mt-6 space-y-4">
            <div>
                <label for="email" class="label">Work email</label>
                <input wire:model="email" id="email" type="email" class="input" autofocus autocomplete="username">
                @error('email') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="label">Password</label>
                <input wire:model="password" id="password" type="password" class="input" autocomplete="current-password">
                @error('password') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="authenticate">Continue</button>
        </form>
    @else
        <form wire:submit="verifyMfa" class="mt-6 space-y-4">
            <div>
                <label for="code" class="label">Authentication code</label>
                <input wire:model="code" id="code" type="text" inputmode="numeric" autocomplete="one-time-code" class="input mono" autofocus placeholder="123456 or a recovery code">
                @error('code') <p class="field-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="verifyMfa">Verify</button>
        </form>
    @endif
</div>
