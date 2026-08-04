<?php

declare(strict_types=1);

use App\Mail\EmailVerificationMail;
use App\Platform\AccountAuth;
use App\Platform\MailLinks;
use App\Platform\PlatformAuth;
use App\Platform\RiskGuard;
use App\Platform\SignupPolicy;
use App\Platform\SignupProvisioner;
use App\Platform\SsoStart;
use App\Platform\Turnstile;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth', ['title' => 'Get started'])] class extends Component
{
    public string $organization = '';

    public string $name = '';

    public string $email = '';

    public string $password = '';

    // Honeypot: a real user never fills `website`; `renderedAt` catches bots that
    // submit implausibly fast. Both feed the risk scorer.
    public string $website = '';

    public int $renderedAt = 0;

    // Set once the risk scorer has asked this submission to be challenged: the CAPTCHA
    // widget renders from here on, and the token it produces comes back in
    // `turnstileToken`. Both stay empty for the overwhelming majority of signups, which
    // are never challenged and never see a CAPTCHA at all.
    public bool $challenged = false;

    public string $turnstileToken = '';

    public function mount(SignupPolicy $signup): mixed
    {
        // Self-service signup can be closed or invite-only — send would-be
        // registrants to sign-in with an explanation rather than a dead form.
        if (! $signup->isOpen()) {
            return redirect()->route('login')->with('status', $signup->closedMessage());
        }

        $this->renderedAt = now()->getTimestamp();

        return null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'organization' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            // NIST SP 800-63B favors length over composition, so the tenant's policy
            // sets a length floor and a known-breach screen (HIBP k-anonymity) and
            // demands no composition. There is no subject yet, so no reuse history.
            'password' => ['required', 'string', 'max:200', PasswordMeetsPolicy::for()],
        ];
    }

    public function register(Subjects $subjects, Organizations $orgs, Memberships $memberships, PlatformAuth $auth, RiskGuard $risk, SignupPolicy $signup, DomainVerification $domains, Turnstile $turnstile, MailLinks $links): void
    {
        // Defense in depth: never create an account when signup isn't open, even
        // if the form was reached or replayed out of band.
        abort_unless($signup->isOpen(), 403);

        $this->validate();

        // Throttle to blunt account-enumeration and automated signup abuse.
        $key = 'signup|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        RateLimiter::hit($key, 300);

        // Risk-score the signup (bot/abuse detection). Logged for review; blocks a
        // Reject only when enforcement is enabled.
        $assessment = $risk->assess(request(), 'register', $this->email, [
            'honeypot' => $this->website,
            'form_rendered_at' => $this->renderedAt,
        ]);

        if ($risk->shouldBlock($assessment)) {
            $this->addError('email', 'We could not process this request. Please try again later.');

            return;
        }

        // An elevated-but-not-reject outcome (Challenge / StepUp) is where a CAPTCHA
        // belongs: the scorer has already decided this submission is unusual, so the
        // friction lands on it and not on everyone else. Turnstile with no keys
        // configured verifies as true, so a deployment without it keeps today's flow.
        if ($risk->shouldStepUp($assessment) && ! $turnstile->verify($this->turnstileToken, request()->ip())) {
            // Show the widget (this may be the first the submitter sees of it) and burn
            // the token that failed — Turnstile tokens are single-use, so a retry must
            // carry a fresh one.
            $this->challenged = true;
            $this->turnstileToken = '';
            $this->addError('email', 'Please complete the verification below, then submit again.');

            return;
        }

        // Tier 2 — on the platform root (cboxid.com), a standalone signup provisions
        // the signer's own ACCOUNT: a workspace, its first member (them), a first
        // project (their first IdP product), and that project's first environment —
        // their own IdP. Cbox ID is the product here. This runs
        // before the Tier-1 checks below because it's a different plane entirely: the
        // signer becomes a global account member, not a Subject in Cbox's own
        // environment. During an SSO flow (joining an app) or on a customer's own
        // environment it stays a Tier-1 join, which is what keeps IdP-creation a
        // root-only capability that never recurses into a customer's environment.
        if ($this->provisionsOwnIdp(app(EnvironmentContext::class))) {
            $members = app(AccountMembers::class);

            // Account-member emails are globally unique — one email, one root login.
            if ($members->findByEmail($this->email) !== null) {
                $this->addError('email', 'An account with this email already exists.');

                return;
            }

            try {
                // NOTE what this does NOT create: the environment. A self-serve signup
                // gets its account, its home organization, its owner and its first
                // project — but the routable, key-bearing IdP is released only once the
                // owner clicks the link in their inbox (see SignupProvisioner). That is
                // what makes a bot signup worthless rather than merely inconvenient.
                $result = app(SignupProvisioner::class)->provisionPending(new AccountBlueprint(
                    accountName: trim($this->organization),
                    ownerEmail: $this->email,
                    ownerName: trim($this->name) ?: null,
                    ownerPassword: $this->password,
                ));
            } catch (QueryException $e) {
                // Two concurrent signups for the same email both clear the check
                // above, then race the unique index. The loser's whole transaction
                // rolls back (no partial state) — surface the same friendly error
                // instead of a 500.
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }

                $this->addError('email', 'An account with this email already exists.');

                return;
            }

            $subjectId = $result->member->subject_id;

            if (is_string($subjectId) && $subjectId !== '') {
                $token = app(EmailVerification::class)->issue($subjectId, $this->email);
                Mail::to($this->email)->send(new EmailVerificationMail($links->route('verification.verify', $token)));
            } else {
                // No platform root yet (the first-install bootstrap window) — the member
                // has no subject, so no verification token can be bound to them. Release
                // the environment immediately rather than stranding the install behind a
                // link that can never be issued.
                app(SignupProvisioner::class)->releaseEnvironment($result->member);
            }

            // The buyer administers every environment they own from the root
            // workspace — sign them straight in there, not into an environment's
            // own domain. This is the account plane's single sign-in.
            app(AccountAuth::class)->establish($result->member->id);
            session()->flash('status', 'Workspace created. Confirm your email to finish setting up your first environment.');
            $this->redirect(route('projects'), navigate: false);

            return;
        }

        // Capture gate: when an admin has flagged the email's verified domain for
        // capture AND the org has an active SSO connection, a local password account
        // is refused — the user must sign in through their org's IdP. Only bites for
        // domains explicitly flagged; non-captured domains fall through untouched.
        if ($domains->forEmail($this->email)?->capture === true
            && ($connection = $domains->connectionForEmail($this->email)) !== null) {
            session()->flash('status', 'Your organization requires signing in through SSO.');
            $this->redirect(SsoStart::url($connection), navigate: false);

            return;
        }

        if ($subjects->findByEmail($this->email) !== null) {
            $this->addError('email', 'An account with this email already exists.');

            return;
        }

        $subject = $subjects->create($this->email, $this->name, $this->password);

        $organization = $orgs->create(new NewOrganization($this->organization, $this->uniqueSlug($orgs)));
        $memberships->add($organization->id, $subject->id, MembershipRole::Owner);

        // Send a confirmation link; the account is usable immediately, verification
        // just confirms the address out of band.
        $verifyToken = app(EmailVerification::class)->issue($subject->id, $this->email);
        Mail::to($this->email)->send(new EmailVerificationMail($links->route('verification.verify', $verifyToken)));

        $auth->establish(request(), $subject->id, ['pwd']);

        $this->redirectRoute('dashboard', navigate: false);
    }

    /**
     * True only on the platform-root environment (the one that sells IdPs) AND when
     * this isn't part of an SSO flow (no pending OAuth authorize). A customer's own
     * environment is never the default, so its signups can never provision new IdPs.
     */
    private function provisionsOwnIdp(EnvironmentContext $env): bool
    {
        // SaaS multi-tenant only. A self-hosted install is a single forced IdP: no
        // base domains configured → no subdomain routing → nowhere to route a new
        // environment, so signup stays a plain Tier-1 join. This one flag is the
        // whole difference between the hosted product and single-tenant self-hosting.
        if (! $this->multiTenant()) {
            return false;
        }

        $current = $env->current()?->environmentKey();

        if ($current === null) {
            return false;
        }

        $isRoot = Environment::query()->where('is_default', true)->whereKey($current)->exists();
        $intended = session()->get('url.intended');
        $inSsoFlow = is_string($intended) && str_contains($intended, 'oauth');

        return $isRoot && ! $inSsoFlow;
    }

    private function multiTenant(): bool
    {
        $bases = config('cbox-id.environments.base_domains', []);

        return is_array($bases) && $bases !== [];
    }

    /** A unique-index violation across the supported drivers (Postgres/MySQL/SQLite). */
    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 = Postgres unique_violation; 23000 = MySQL integrity constraint.
        return in_array((string) $e->getCode(), ['23505', '23000'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    /**
     * @return array<string, bool|string>
     */
    public function with(): array
    {
        // On the platform root this signup mints the signer's OWN IdP, so the page
        // says so; elsewhere it's an ordinary join.
        return [
            'createsIdp' => $this->provisionsOwnIdp(app(EnvironmentContext::class)),
            // '' when Turnstile isn't configured — the widget and its script are then
            // never referenced at all, which is what keeps the CSP tight.
            'turnstileSiteKey' => app(Turnstile::class)->siteKey(),
        ];
    }

    private function uniqueSlug(Organizations $orgs): string
    {
        $base = Str::slug($this->organization) ?: 'org';
        $slug = $base;
        $n = 1;

        while ($orgs->bySlug($slug) !== null) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}; ?>

<div>
    <h1 class="font-semibold tracking-tight" style="font-size:1.7rem">{{ $createsIdp ? 'Create your identity platform' : 'Create your organization' }}</h1>
    <p class="mt-2 text-sm" style="color:var(--muted)">{{ $createsIdp ? 'Your own hosted IdP — SSO, users, and sign-in you fully control, live in a minute.' : 'Set up Cbox ID for your team in under a minute.' }}</p>

    {{-- name + autocomplete so password managers offer to save; passwordrules/minlength
         let them generate a policy-compliant password (NIST: length over complexity). --}}
    <form wire:submit="register" class="mt-7 space-y-4" method="post" action="{{ route('signup') }}">
        {{-- Honeypot: hidden from humans, tempting to bots. Must stay empty. --}}
        <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px" tabindex="-1">
            <label for="website">Website</label>
            <input wire:model="website" id="website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>

        <div>
            <label class="label" for="organization">{{ $createsIdp ? 'Name your platform' : 'Organization name' }}</label>
            <input wire:model="organization" id="organization" name="organization" type="text" autocomplete="organization" class="input input-lg" placeholder="Acme Inc." autofocus
                   @error('organization') aria-invalid="true" aria-describedby="organization-error" @enderror>
            @error('organization') <p class="field-error" id="organization-error" role="alert">{{ $message }}</p> @enderror
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="label" for="name">Your name</label>
                <input wire:model="name" id="name" name="name" type="text" autocomplete="name" class="input input-lg" placeholder="Dana Reeves"
                       @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                @error('name') <p class="field-error" id="name-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="email">Work email</label>
                <input wire:model="email" id="email" name="email" type="email" inputmode="email"
                       autocomplete="username" autocapitalize="none" spellcheck="false"
                       class="input input-lg" placeholder="dana@acme.com"
                       @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                @error('email') <p class="field-error" id="email-error" role="alert">{{ $message }}</p> @enderror
            </div>
        </div>

        <div x-data="{ pw: '' }">
            <label class="label" for="password">Password</label>
            <input wire:model="password" x-on:input="pw = $event.target.value"
                   id="password" name="password" type="password"
                   autocomplete="new-password" minlength="12" passwordrules="minlength: 12; allowed: ascii-printable;"
                   class="input input-lg" placeholder="At least 12 characters"
                   aria-describedby="password-policy @error('password') password-error @enderror"
                   @error('password') aria-invalid="true" @enderror>
            <div id="password-policy" class="mt-2 flex items-center gap-1.5 text-xs" style="color:var(--faint)">
                <x-icon name="check" class="w-3.5 h-3.5" x-bind:style="pw.length >= 12 ? 'color:var(--success-strong)' : ''" />
                <span x-bind:style="pw.length >= 12 ? 'color:var(--success-strong)' : ''">At least 12 characters</span>
                <span class="mx-1" aria-hidden="true">·</span>
                <span>checked against known breaches</span>
            </div>
            @error('password') <p class="field-error" id="password-error" role="alert">{{ $message }}</p> @enderror
        </div>

        {{-- Risk-triggered CAPTCHA. The host element is present whenever Turnstile is
             configured (it is what the bundled JS watches), but the widget itself only
             appears once the risk scorer has challenged this submission — a clean signup
             never loads Cloudflare's script and never sees a challenge. The token
             arrives via a window event dispatched from bundled, same-origin JS, so no
             inline script is needed and the CSP stays script-src 'self'. --}}
        @if ($turnstileSiteKey !== '')
            <div data-turnstile-host x-data x-on:cbox-turnstile.window="$wire.set('turnstileToken', $event.detail)">
                @if ($challenged)
                    <div wire:ignore class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-callback="cboxTurnstileToken" data-theme="auto"></div>
                    <p class="mt-2 text-xs" style="color:var(--muted)">A quick check that you're not a bot. It usually completes on its own.</p>
                @endif
            </div>
        @endif

        <button type="submit" class="btn btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="register">
            <span wire:loading.remove wire:target="register">{{ $createsIdp ? 'Create identity platform' : 'Create organization' }}</span>
            <span wire:loading wire:target="register" class="inline-flex items-center gap-2"><span class="spinner"></span> Creating…</span>
        </button>
    </form>

    <p class="mt-8 text-sm text-center" style="color:var(--muted)">
        Already have an account? <a href="{{ route('login') }}" class="font-medium underline underline-offset-2" style="color:var(--accent-strong)">Sign in</a>
    </p>
</div>
