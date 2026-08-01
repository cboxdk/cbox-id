<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use App\Platform\MemberCredentialGate;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\Contracts\AccountMemberMfa;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Models\AccountMember;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * "Sign in as admin" — the tenant subdomain's ADMIN door. It authenticates a
 * CONTROL-PLANE identity: an account member, whose credential is their subject in the
 * PLATFORM-ROOT environment — never a subject inside the environment being administered.
 * On success it establishes an environment-admin session, keyed on that subject and
 * bound to THIS host's environment. The end-user sign-in (for the tenant's own apps) is
 * a separate door — no layer confusion.
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

    /**
     * 'password' → 'mfa'. LOCKED, with the pending id: both are server-set state that
     * decides whether the password step has been passed, and a plain public property is
     * client-set — a crafted Livewire update could put this component straight into the
     * MFA step for an arbitrary member id. Locking is the first of two layers; the
     * second is {@see admitsAdmin()}, re-run before the session is established.
     */
    #[Locked]
    public string $step = 'password';

    #[Locked]
    public string $pendingMemberId = '';

    public function mount(EnvironmentContext $environments): mixed
    {
        // On a multi-tenant deployment the admin door lives at the ROOT — account
        // credentials are never entered on a tenant-controlled host (see
        // {@see \App\Http\Middleware\AuthenticateEnvironmentAdmin}). If someone reaches
        // this local form directly, bounce them to the root's "open environment"
        // handoff for THIS environment so they authenticate once, at the root.
        $bases = config('cbox-id.environments.base_domains', []);
        $root = is_array($bases) && isset($bases[0]) && is_string($bases[0]) && $bases[0] !== ''
            ? $bases[0]
            : null;
        $environment = $environments->current();

        if ($root !== null && $environment !== null) {
            return redirect()->away(
                'https://'.$root.route('workspace.environment.open', $environment->environmentKey(), false)
            );
        }

        return null;
    }

    public function authenticate(AccountMembers $members, AccountMemberMfa $mfa, EnvironmentContext $environments, EnvironmentAdminAuth $auth, MemberCredentialGate $gate): void
    {
        $this->validate(['email' => 'required|email', 'password' => 'required|string']);

        $key = 'admin-login|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        $member = $members->findByEmail($this->email);

        // The per-subject lockout binds here too, and is asked BEFORE the credential so a
        // locked account cannot be used to tell a right guess from a wrong one.
        if ($gate->isLockedOut($member)) {
            RateLimiter::hit($key);
            $this->addError('email', 'Those credentials do not grant admin access to this environment.');

            return;
        }

        $ok = $member !== null && $members->verifyPassword($member->id, $this->password);

        // Constant-cost miss path — no enumeration timing oracle.
        if ($member === null) {
            $members->verifyPassword('', $this->password);
        }

        // Fail identically for wrong credentials AND for a valid member with no access
        // to THIS environment — never reveal which.
        $hostEnv = $environments->current()?->environmentKey();
        $hasAccess = $ok && $hostEnv !== null && $this->admitsAdmin($members, $member, $hostEnv);

        if (! $ok || ! $hasAccess) {
            RateLimiter::hit($key);
            $gate->recordFailure($member);
            $this->addError('email', 'Those credentials do not grant admin access to this environment.');

            return;
        }

        // The rules a verified password still has to satisfy — the SSO mandate and the
        // expiry on an administratively-issued temporary password. This door checked
        // NEITHER, so an environment mandating SSO could be entered with a local password
        // here, and an expired hand-off credential kept working. Same gate the account
        // door asks, so the two cannot drift apart again.
        if (! $gate->admits($member)) {
            RateLimiter::hit($key);
            $this->addError('email', 'Those credentials do not grant admin access to this environment.');

            return;
        }

        // A password the administrator who issued it also knows has no business opening
        // the highest-privilege surface on a tenant. The console planes HOLD such a
        // member on a change page; this one refuses, because there is no page here on
        // which an account credential can be changed. Said plainly rather than as the
        // uniform failure above — the person is already authenticated, so there is
        // nothing left to disclose, and "wrong credentials" would send them in circles.
        if ($gate->owesPasswordChange($member)) {
            $this->addError('email', 'This password was issued by an administrator and must be replaced before it can be used here. Change it from your account console first.');

            return;
        }

        $gate->clearFailures($member);

        if ($mfa->hasConfirmedTotp($member->id)) {
            $this->pendingMemberId = $member->id;
            $this->step = 'mfa';

            return;
        }

        $this->establish($auth, $member, $hostEnv);
    }

    /**
     * May this member open an admin session on THIS environment?
     *
     * Both steps ask this, which is the point. The password step asked it and the MFA
     * step did not, so a member who never passed the password step — and one the
     * password step would have refused: a role without canManageEnvironments(), an
     * environment outside their assignment, a locked-out account, an SSO mandate, an
     * expired administrative password — could reach {@see establish()} with only a TOTP
     * code. Asked here, once, so the two doors cannot drift apart again.
     */
    private function admitsAdmin(AccountMembers $members, AccountMember $member, string $hostEnv): bool
    {
        return $member->role->canManageEnvironments()
            && in_array($hostEnv, $members->accessibleEnvironmentIds($member), true);
    }

    public function verifyMfa(AccountMembers $members, AccountMemberMfa $mfa, EnvironmentContext $environments, EnvironmentAdminAuth $auth, MemberCredentialGate $gate): void
    {
        $this->validate(['code' => 'required|string']);

        if ($this->pendingMemberId === '') {
            $this->step = 'password';

            return;
        }

                // Keyed to the pending member alone — NOT the source IP. With the IP in the key
        // an attacker holding the password rotates addresses and gets a fresh bucket of
        // five each time. TOTP rolls every 30 seconds so that is slow; recovery codes do
        // not roll, which is the half that actually gives way. The subject plane has
        // always keyed it this way, with a comment saying an attacker must not be able to
        // grind it.
        $key = 'admin-mfa|'.$this->pendingMemberId;
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

        $member = $members->find($this->pendingMemberId);

        if ($member === null) {
            $this->step = 'password';

            return;
        }

        // The second layer. A correct TOTP code proves possession of a factor, not that
        // this member may administer this environment — and the state saying they passed
        // the password step is client-adjacent. Re-ask every question the password step
        // asked, against the same gate, before any session exists.
        if (! $this->admitsAdmin($members, $member, $hostEnv)
            || $gate->isLockedOut($member)
            || ! $gate->admits($member)
            || $gate->owesPasswordChange($member)) {
            $this->reset('pendingMemberId');
            $this->step = 'password';
            $this->addError('email', 'Those credentials do not grant admin access to this environment.');

            return;
        }

        $this->establish($auth, $member, $hostEnv);
    }

    /**
     * The admin session is keyed on the member's PLATFORM-ROOT SUBJECT, because that is
     * the credential of record. A member without one has no control-plane identity to
     * bind (the first-install bootstrap window only) and is refused rather than being
     * given a session keyed on something the guard cannot resolve.
     */
    private function establish(EnvironmentAdminAuth $auth, AccountMember $member, string $environmentId): void
    {
        $subjectId = $member->subject_id;

        if ($subjectId === null) {
            $this->step = 'password';
            $this->addError('email', 'Those credentials do not grant admin access to this environment.');

            return;
        }

        $auth->establish($subjectId, $environmentId);
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
