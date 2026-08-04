<?php

declare(strict_types=1);

use App\Mail\MagicLinkMail;
use App\Platform\AccountAuth;
use App\Platform\Console\ConsoleScope;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\MailLinks;
use App\Platform\SsoStart;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Workspace sign-in — the account-member (buyer) plane. One root login for the
 * whole account, from which the member reaches every environment they own. This
 * is the answer to "I forgot my subdomain / I have several": sign in here, not on
 * an environment's own domain.
 *
 * IDENTIFIER-FIRST, exactly like the subject door, because it now IS the subject door
 * wearing account clothes: account members are ordinary subjects in the platform-root
 * environment, so the email step can do real home-realm discovery — a verified domain
 * with an active connection on the account's organization redirects to that IdP, and
 * account-layer SSO is an ordinary {@see Connection} rather than a second federation
 * stack. Magic links work here for the same reason. See
 * docs/core-concepts/unified-account-identity.md.
 */
new #[Layout('components.layouts.auth', ['title' => 'Workspace sign in'])] class extends Component
{
    public string $email = '';

    public string $password = '';

    /** Home-realm discovery: true once the email step passed with no SSO connection, revealing the password form. */
    public bool $identified = false;

    public bool $magicSent = false;

    public ?string $magicUrl = null;

    /**
     * Already signed in? Go where this session belongs.
     *
     * `$auth->check()` alone was the wrong question: it asks for a MEMBER session, and an
     * operator with no account holds a subject one. So a signed-in operator was shown the
     * sign-in form as though they were a stranger, and signing in again put them back
     * here. {@see ConsoleScope::signedIn()} answers for every shape of session, and the
     * console root admits an operator with no account, so there is one place to land.
     */
    public function mount(ConsoleScope $scope): mixed
    {
        if ($scope->signedIn()) {
            return redirect()->intended(route('workspace.home'));
        }

        return null;
    }

    /**
     * Identifier-first step: discover the email's home realm. A verified domain with an
     * active SSO connection redirects straight to the IdP; anything else falls through
     * to the local password form (revealed by `identified`).
     */
    public function continue(): void
    {
        $this->validate(['email' => 'required|email|max:190']);

        if ($this->redirectHomeRealm()) {
            return;
        }

        $this->identified = true;
    }

    public function login(AccountAuth $auth): void
    {
        $this->validate([
            'email' => 'required|email|max:190',
            'password' => 'required|string',
        ]);

        // Home-realm discovery gates the password path too: a verified domain with an
        // active connection is ALWAYS routed to its IdP, never authenticated locally,
        // even when the password form was reached directly. Otherwise "require SSO"
        // would be a suggestion.
        if ($this->redirectHomeRealm()) {
            return;
        }

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

    /**
     * Email a one-time sign-in link. Only ever issued to an address that is already an
     * account member: redemption provisions a subject on first sight, so an unqualified
     * link would be a way to mint one. The confirmation shows either way, so the page
     * never reveals whether an address is a member.
     */
    public function sendMagicLink(MagicLink $links, AccountMembers $members, PlatformRoot $root, MailLinks $mailLinks): void
    {
        $this->validate(['email' => 'required|email|max:190']);

        $key = 'workspace-magic|'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('email', 'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        RateLimiter::hit($key, 120);

        $member = $members->findByEmail($this->email);

        if ($member !== null && $member->isActive() && $member->subject_id !== null) {
            // Issued in the platform root's scope — that is where the account's subjects
            // and their link tokens live.
            $token = $root->run(fn (): string => $links->request($this->email));

            if (is_string($token)) {
                // MailLinks, not route(). This one carries a BEARER token in the path and
                // nothing signs the origin, so a poisoned Host needed no replay trick at
                // all — the recipient's click handed the token straight over.
                $url = $mailLinks->route('workspace.magic.redeem', $token);

                Mail::to($this->email)->send(new MagicLinkMail($url));

                // Also surface the link directly in local dev (never on staging/prod).
                $this->magicUrl = app()->environment('local') ? $url : null;
            }
        }

        $this->magicSent = true;
    }

    /**
     * Route the email's home realm to its IdP if it has one. Returns true when a
     * redirect was issued (the caller must stop), false to continue with the local flow
     * — keeping the "verified domain → always SSO, never local auth" invariant in one
     * place for both the identifier step and a direct password submit.
     *
     * Discovery runs in the PLATFORM ROOT's scope: the account's organization, its
     * verified domains and its connections all live there, and connectionForEmail is
     * deny-by-default (a VERIFIED domain with an ACTIVE connection, or nothing).
     */
    private function redirectHomeRealm(): bool
    {
        if (! str_contains($this->email, '@')) {
            return false;
        }

        $connection = app(PlatformRoot::class)->run(
            fn (): ?Connection => app(DomainVerification::class)->connectionForEmail($this->email),
        );

        if (! $connection instanceof Connection) {
            return false;
        }

        $this->redirect(SsoStart::url($connection), navigate: false);

        return true;
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Sign in to your workspace</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">Manage your environments, users, and sign-in — all from one place.</p>

    {{-- Identifier-first step 2 is MORPHED in: no navigation, no focus move, no
         announcement — the password field just silently appears. This region sits
         OUTSIDE the @if so Livewire morphs its text rather than inserting the region
         itself (a live region inserted already-populated is not reliably spoken). --}}
    <p role="status" aria-live="polite" class="sr-only">
        @if ($identified)
            Password required. Enter the password for {{ $email }}.
        @endif
    </p>

    @if (session('error'))
        <div role="alert" class="mt-5 rounded-lg px-3.5 py-2.5 text-sm" style="background:var(--danger-soft);color:var(--danger-strong)">
            {{ session('error') }}
        </div>
    @endif

    @if ($magicSent)
        <div role="status" aria-live="polite" class="mt-5 rounded-lg px-3.5 py-3 text-sm card" style="padding:0.85rem 1rem">
            <p class="font-medium">Check your inbox</p>
            <p class="mt-1" style="color:var(--muted)">If <b>{{ $email }}</b> belongs to a workspace, we sent it a one-time sign-in link.</p>
            @if ($magicUrl)
                <a href="{{ $magicUrl }}" class="mt-2 inline-block text-sm underline underline-offset-2 mono" style="color:var(--accent-strong);word-break:break-all">{{ $magicUrl }}</a>
                <p class="mt-1 text-xs" style="color:var(--faint)">Shown because email isn't configured in this environment.</p>
            @endif
        </div>
    @endif

    {{-- Identifier-first: enter the email, discover its home realm, THEN reveal the
         password. A verified domain with active SSO redirects to the IdP instead. --}}
    @if (! $identified)
        <form wire:submit="continue" class="mt-7 space-y-4">
            <div>
                <label class="label" for="email">Work email</label>
                <input wire:model="email" id="email" name="email" type="email" inputmode="email"
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       class="input input-lg" placeholder="you@yourco.example" autofocus
                       @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email') <p class="field-error" id="email-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="continue">
                <span wire:loading.remove wire:target="continue">Continue</span>
                <span wire:loading wire:target="continue" class="inline-flex items-center gap-2"><span class="spinner"></span> Continuing…</span>
            </button>
        </form>
    @else
        <form wire:submit="login" class="mt-7 space-y-4">
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="label" for="email" style="margin-bottom:0">Work email</label>
                    <button type="button" wire:click="$set('identified', false)" class="text-xs font-medium underline underline-offset-2" style="color:var(--accent-strong)">Use a different email</button>
                </div>
                <input wire:model="email" id="email" name="email" type="email" autocomplete="username" autocapitalize="none" spellcheck="false"
                       class="input input-lg" placeholder="you@yourco.example"
                       @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email') <p class="field-error" id="email-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <div class="flex items-center justify-between">
                    <label class="label" for="password">Password</label>
                    <a href="{{ route('workspace.password.request') }}" class="text-xs underline underline-offset-2" style="color:var(--accent-strong)">Forgot password?</a>
                </div>
                {{-- x-init, not autofocus: HTML autofocus only fires at document parse,
                     and this input is morphed in after it — so focus stayed on <body>. --}}
                <input wire:model="password" id="password" name="password" type="password" autocomplete="current-password" class="input input-lg" placeholder="••••••••••••"
                       x-init="$el.focus()"
                       @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                @error('password') <p class="field-error" id="password-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="login">
                <span wire:loading.remove wire:target="login">Sign in</span>
                <span wire:loading wire:target="login" class="inline-flex items-center gap-2"><span class="spinner"></span> Signing in…</span>
            </button>
        </form>
    @endif

    <div class="mt-5 flex items-center gap-3 text-xs" style="color:var(--faint)">
        <span class="flex-1" style="height:1px;background:var(--border)"></span> or <span class="flex-1" style="height:1px;background:var(--border)"></span>
    </div>
    <div class="space-y-2.5 mt-5">
        <button type="button" wire:click="sendMagicLink" class="btn btn-ghost btn-lg w-full" wire:loading.attr="disabled" wire:target="sendMagicLink">
            <x-icon name="magic" class="w-4 h-4" /> Email me a magic link
        </button>
        <button type="button" class="btn btn-ghost btn-lg w-full"
                data-passkey-login data-passkey-base="/workspace/passkeys" data-passkey-feedback="pk-login-feedback">
            <x-icon name="key" class="w-4 h-4" /> Sign in with a passkey
        </button>
        <p id="pk-login-feedback" class="text-xs text-center" aria-live="polite"></p>
    </div>

    <p class="mt-8 text-sm text-center" style="color:var(--muted)">
        Don't have a workspace yet? <a href="{{ route('signup') }}" class="font-medium underline underline-offset-2" style="color:var(--accent-strong)">Create your identity platform</a>
    </p>
</div>
