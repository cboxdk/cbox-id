<?php

declare(strict_types=1);

use App\Mail\MagicLinkMail;
use App\Platform\PlatformAuth;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\Enums\RefusedFactor;
use App\Platform\MailLinks;
use App\Platform\RiskGuard;
use App\Platform\SamlSsoHandoff;
use App\Platform\SignupPolicy;
use App\Platform\SsoMandate;
use App\Platform\SsoMandates;
use App\Platform\SsoRefusal;
use App\Platform\SsoStart;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Organizations;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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
     * The organization whose branded sign-in page this is, when there is one.
     *
     * Locked: it decides which tenant's provider credentials the social buttons use, so
     * a value the browser could edit would let anyone render another tenant's buttons —
     * and start a flow that lands an account in the wrong organization.
     */
    #[Locked]
    public ?string $brandedOrgId = null;

    /** Home-realm discovery: true once the email step passed with no SSO connection, revealing the password form. */
    public bool $identified = false;

    /**
     * The organization that refused this password because it mandates SSO, once one has.
     *
     * Held as state rather than shown as a flash, because it is TERMINAL: there is no
     * password this form could accept afterwards, so the form itself goes away. The
     * refusal used to be an error on the email field reading "those credentials do not
     * match our records" — to the one population that had typed them correctly.
     */
    public ?string $ssoOrganization = null;

    public ?string $ssoStartUrl = null;

    /**
     * What happened, in the words of the door it happened at.
     *
     * This screen is now the terminal screen for every door — the password form below, a
     * redeemed magic link, a passkey ceremony, a social callback, an accepted invitation —
     * and "your password is correct" is true at exactly one of them. See
     * {@see RefusedFactor}.
     */
    public string $ssoReason = '';

    /**
     * Branded, per-organization login (/o/{slug}/login) themes the page with the
     * org's colour, logo and name.
     */
    public function mount(?string $slug = null): void
    {
        // A refusal from a door that could not explain itself. Those doors are controllers
        // and JSON endpoints: they redirect here holding only WHO was refused and WHAT
        // they proved, and the organization is resolved below through the one lookup that
        // knows how to name it.
        $refusal = SsoRefusal::take();

        if ($refusal !== null) {
            $this->applyMandate(app(SsoMandates::class)->forSubject($refusal->subjectId), $refusal->factor);
        }

        $this->pendingLink = app(PlatformAuth::class)->pendingLink()?->label();

        if ($slug === null) {
            return;
        }

        $org = app(Organizations::class)->bySlug($slug);

        if ($org !== null) {
            $this->brandedOrgId = $org->id;

            // Carry the org's whole settings bag — the auth layout resolves both the
            // logo and the full custom sign-in appearance (Theme Editor) from it.
            View::share('cboxBrand', [
                'name' => $org->name,
                'settings' => $org->settings,
            ]);
        }
    }

    /**
     * Identifier-first step: discover the email's home realm. A verified domain
     * with an active SSO connection redirects straight to the IdP; anything else
     * falls through to the local password form (revealed by `identified`).
     */
    public function continue(): void
    {
        $this->validateOnly('email');

        if ($this->redirectHomeRealm()) {
            return;
        }

        $this->identified = true;
    }

    public function login(PlatformAuth $auth, RiskGuard $risk, SsoMandates $mandates): void
    {
        $this->validate();

        // Home-realm discovery also gates the password path: a verified domain with
        // an active SSO connection is always routed to the IdP, never authenticated
        // locally, even if the password form was reached directly.
        if ($this->redirectHomeRealm()) {
            return;
        }

        $key = $this->throttleKey('login');

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        // Risk-score the attempt (credential-stuffing / bot velocity, IP reputation,
        // Tor). Logged for review. Under enforcement a Reject hard-blocks, and an
        // elevated-but-not-reject outcome demands a step-up second factor below.
        $assessment = $risk->assess(request(), 'login', $this->email);

        if ($risk->shouldBlock($assessment)) {
            $this->addError('email', 'We could not process this request. Please try again later.');

            return;
        }

        $result = $auth->attemptPassword(request(), $this->email, $this->password, $risk->shouldStepUp($assessment));

        if ($result === AttemptOutcome::Invalid) {
            RateLimiter::hit($key, 60);
            $this->addError('email', 'Those credentials do not match our records.');

            return;
        }

        // A mandate, not a bad password: the credential was right and will go on being
        // refused, so this is where the form stops and the IdP link takes over. The
        // limiter is neither hit nor cleared — this was not a failed guess, and no
        // session was established either.
        if ($result === AttemptOutcome::SsoRequired) {
            $this->showSsoMandate($mandates);

            return;
        }

        RateLimiter::clear($key);

        if ($result === AttemptOutcome::Mfa) {
            $this->redirectRoute('mfa', navigate: false);

            return;
        }

        // Elevated risk on an account with no authenticator: step up with an emailed
        // one-time code before the session is established.
        if ($result === AttemptOutcome::Otp) {
            $this->redirectRoute('login.step-up', navigate: false);

            return;
        }

        // Resume an in-flight SAML sign-on (the subject was bounced here mid-SSO);
        // else the intended URL stashed when auth bounced us here (e.g. an
        // /oauth/authorize the user was completing); else the console.
        $intended = session()->pull('url.intended');
        $this->redirect(
            app(SamlSsoHandoff::class)->resumeUrl()
                ?? (is_string($intended) && $intended !== '' ? $intended : route('dashboard')),
            navigate: false,
        );
    }

    public function sendMagicLink(MagicLink $links, Subjects $subjects, SignupPolicy $signup, MailLinks $mailLinks): void
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
            $url = $mailLinks->route('magic.redeem', $token);

            Mail::to($this->email)->send(new MagicLinkMail($url));

            // Also surface the link directly in local dev (never on staging/prod).
            $this->magicUrl = app()->environment('local') ? $url : null;
        }

        $this->magicSent = true;
    }

    /**
     * The active SSO connection this email should be routed to via its verified
     * domain, or null. connectionForEmail() is deny-by-default — it only matches a
     * VERIFIED domain with an ACTIVE connection.
     */
    private function ssoConnectionForEmail(): ?Connection
    {
        if (! str_contains($this->email, '@')) {
            return null;
        }

        return app(DomainVerification::class)->connectionForEmail($this->email);
    }

    /**
     * Route the email's home realm to its IdP if it has one. Returns true when a
     * redirect was issued (the caller must stop), false to continue with the local
     * flow — keeping the "verified domain → always SSO, never local auth" invariant
     * in one place for both the identifier step and a direct password submit.
     */
    private function redirectHomeRealm(): bool
    {
        if (($connection = $this->ssoConnectionForEmail()) === null) {
            return false;
        }

        $this->redirect(SsoStart::url($connection), navigate: false);

        return true;
    }

    /**
     * Leave the mandate screen for a fresh identifier step.
     *
     * Back to the EMAIL step, not to the password form: the person who reaches this
     * screen has been told their address signs in somewhere else, so the next useful
     * thing they can do is type a different one.
     */
    public function startOver(): void
    {
        $this->reset('ssoOrganization', 'ssoStartUrl', 'ssoReason', 'identified', 'password');
    }

    /**
     * Turn this door's own mandate refusal into the terminal screen.
     */
    private function showSsoMandate(SsoMandates $mandates): void
    {
        $subject = app(Subjects::class)->findByEmail($this->email);

        $this->applyMandate(
            $subject === null ? null : $mandates->forSubject($subject->id),
            RefusedFactor::Password,
        );
    }

    /**
     * Render a mandate, wherever it was refused.
     *
     * One method for both entry points — this page's own password form and the doors that
     * redirect here — because the screen they produce has to be the same screen. Two
     * copies is how one of them would eventually stop naming the organization.
     *
     * The password is dropped on the way: this component is not going to authenticate with
     * it, and a verified credential left in a public property is dehydrated into the
     * wire:snapshot embedded in the page it is about to render.
     */
    private function applyMandate(?SsoMandate $mandate, RefusedFactor $factor): void
    {
        $this->reset('password');

        // Never null in practice — every door reports the refusal only after walking the
        // same memberships — but the lookup is a second read, and a screen that says
        // nothing is worse than one that names no organization.
        $this->ssoOrganization = $mandate === null ? 'Your organization' : $mandate->organizationName;
        $this->ssoStartUrl = $mandate?->startUrl;
        $this->ssoReason = $factor->sentence();
    }

    private function throttleKey(string $action): string
    {
        return $action.'|'.Str::lower($this->email).'|'.request()->ip();
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">Sign in</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">Welcome back. Access your organization's identity console.</p>

    {{-- Identifier-first step 2 is MORPHED in: no navigation, no focus move, no
         announcement — the password field just silently appears. This region sits
         OUTSIDE the @if so Livewire morphs its text rather than inserting the region
         itself (a live region inserted already-populated is not reliably spoken). --}}
    <p role="status" aria-live="polite" class="sr-only">
        @if ($identified)
            Password required. Enter the password for {{ $email }}.
        @endif
    </p>

    @if ($pendingLink)
        <div class="mt-5 rounded-lg px-3.5 py-3 text-sm" style="background:var(--accent-soft);color:var(--accent-strong);border:1px solid color-mix(in srgb,var(--accent) 30%,transparent)">
            <b>Someone signed in with {{ $pendingLink }} using this email.</b> That email already has an account here. Sign in below and we'll ask whether you want to connect {{ $pendingLink }} to it.
        </div>
    @endif

    @if (session('error'))
        <div role="alert" class="mt-5 rounded-lg px-3.5 py-2.5 text-sm" style="background:var(--danger-soft);color:var(--danger-strong)">
            {{ session('error') }}
        </div>
    @endif

    @if ($magicSent)
        <div role="status" aria-live="polite" class="mt-5 rounded-lg px-3.5 py-3 text-sm card" style="padding:0.85rem 1rem">
            <p class="font-medium">Check your inbox</p>
            <p class="mt-1" style="color:var(--muted)">We sent a one-time sign-in link to <b>{{ $email }}</b>.</p>
            @if ($magicUrl)
                <a href="{{ $magicUrl }}" class="mt-2 inline-block text-sm underline underline-offset-2 mono" style="color:var(--accent-strong);word-break:break-all">{{ $magicUrl }}</a>
                <p class="mt-1 text-xs" style="color:var(--faint)">Shown because email isn't configured in this environment.</p>
            @endif
        </div>
    @endif

    {{-- The mandate refusal, and it REPLACES the form rather than sitting above it.
         There is no password this page could accept now, so leaving the fields on screen
         would invite the same attempt again — which is what the old wording ("those
         credentials do not match our records") already did, to people whose credentials
         matched perfectly well. --}}
    @if ($ssoOrganization !== null)
        <div role="alert" class="mt-7 card p-5">
            <h2 class="text-base font-semibold">{{ $ssoOrganization }} requires single sign-on</h2>
            <p class="mt-2 text-sm" style="color:var(--muted)">{{ $ssoReason }}</p>

            @if ($ssoStartUrl !== null)
                {{-- A full navigation, not wire:navigate: the destination is the identity
                     provider's own redirect endpoint, which answers with a cross-origin
                     302 that an SPA navigation cannot follow. --}}
                <a href="{{ $ssoStartUrl }}" class="btn btn-primary btn-lg w-full mt-4">
                    Continue to {{ $ssoOrganization }}
                </a>
            @else
                <p class="mt-4 rounded-lg px-3.5 py-3 text-sm" style="background:var(--danger-soft);color:var(--danger-strong)">
                    No identity provider is connected for {{ $ssoOrganization }} yet, so there is
                    nowhere to send you. Ask an administrator to finish setting up single sign-on.
                </p>
            @endif

            <button type="button" wire:click="startOver" class="btn btn-ghost btn-lg w-full mt-2.5"
                    wire:loading.attr="disabled" wire:target="startOver">Use a different email</button>
        </div>
    @else

    {{-- Identifier-first: enter the email, discover its home realm, THEN reveal the
         password. A verified domain with active SSO redirects to the IdP instead. --}}
    @if (! $identified)
        <form wire:submit="continue" class="mt-7 space-y-4">
            <div>
                <label class="label" for="email">Email</label>
                <input wire:model="email" id="email" name="email" type="email" inputmode="email"
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       class="input input-lg" placeholder="you@company.com" autofocus
                       @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email') <p class="field-error" id="email-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="continue">
                <span wire:loading.remove wire:target="continue">Continue</span>
                <span wire:loading wire:target="continue" class="inline-flex items-center gap-2">
                    <span class="spinner"></span> Continuing…
                </span>
            </button>
        </form>
    @else
        {{-- name + autocomplete so password managers (1Password, iCloud Keychain, browsers) recognise and fill the form. --}}
        <form wire:submit="login" class="mt-7 space-y-4" method="post" action="{{ route('login') }}">
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="label" for="email" style="margin-bottom:0">Email</label>
                    <button type="button" wire:click="$set('identified', false)" class="text-xs font-medium underline underline-offset-2" style="color:var(--accent-strong)">Use a different email</button>
                </div>
                <input wire:model="email" id="email" name="email" type="email" inputmode="email"
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       class="input input-lg" placeholder="you@company.com"
                       @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email') <p class="field-error" id="email-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="label" for="password" style="margin-bottom:0">Password</label>
                    <a href="{{ route('password.request') }}" class="text-xs font-medium underline underline-offset-2" style="color:var(--accent-strong)">Forgot password?</a>
                </div>
                {{-- x-init, not autofocus: HTML autofocus only fires at document parse,
                     and this input is morphed in after it — so focus stayed on <body>. --}}
                <input wire:model="password" id="password" name="password" type="password"
                       autocomplete="current-password" class="input input-lg" placeholder="••••••••••••"
                       x-init="$el.focus()"
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
    @endif

    <div class="divider my-6">OR</div>

    <x-social-buttons class="mb-2.5" :organization-id="$brandedOrgId" />

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
            New organization? <a href="{{ route('signup') }}" class="font-medium underline underline-offset-2" style="color:var(--accent-strong)">Create one</a>
        </p>
    @endif
    @endif
</div>
