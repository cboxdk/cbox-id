<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Requests\Auth\RegisterRequest;
use App\Mail\EmailVerificationMail;
use App\Platform\MailLinks;
use App\Platform\PlatformAuth;
use App\Platform\RiskGuard;
use App\Platform\SignupPolicy;
use App\Platform\SignupProvisioner;
use App\Platform\SsoStart;
use App\Platform\ThrottleScope;
use App\Platform\Turnstile;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * SELF-SERVICE SIGNUP — and it is two different things depending where it is served.
 *
 * On the PLATFORM ROOT of a multi-tenant install it provisions the signer's own ACCOUNT:
 * an organization, its first member (them), and a first project. Cbox ID is the product
 * being bought. Anywhere else — a customer's own environment, or the root during an SSO
 * flow — it is an ordinary Tier-1 join into that environment.
 *
 * That distinction is what keeps IdP-creation a root-only capability that never recurses
 * into a customer's environment, and it is decided from the environment and the pending
 * intent rather than from anything submitted.
 */
final readonly class SignupController extends PageController
{
    public function show(SignupPolicy $signup, Turnstile $turnstile): Response|RedirectResponse
    {
        // Self-service signup can be closed or invite-only. Would-be registrants get an
        // explanation at the sign-in door rather than a form that will refuse them.
        if (! $signup->isOpen()) {
            return to_route('login')->with('status', $signup->closedMessage());
        }

        return $this->page('auth/signup', 'Get started', [
            // On the platform root this signup mints the signer's OWN IdP, so the page
            // says so; elsewhere it is an ordinary join.
            'createsIdp' => $this->provisionsOwnIdp(app(EnvironmentContext::class)),
            /*
             * Empty when Turnstile is not configured, and then the widget and its script
             * are never referenced at all — which is what keeps the policy tight and, more
             * to the point, keeps a third party from seeing every visitor to an identity
             * provider that never needed one.
             */
            'turnstileSiteKey' => $turnstile->siteKey(),
            // The clock the bot heuristic reads back. Stated by the server, because a
            // timestamp the client invents measures nothing.
            'renderedAt' => now()->getTimestamp(),
        ]);
    }

    public function register(
        RegisterRequest $request,
        Subjects $subjects,
        Organizations $orgs,
        Memberships $memberships,
        PlatformAuth $auth,
        RiskGuard $risk,
        SignupPolicy $signup,
        DomainVerification $domains,
        Turnstile $turnstile,
        MailLinks $links,
    ): RedirectResponse {
        // Defence in depth: never create an account when signup is not open, even if the
        // form was reached or replayed out of band.
        abort_unless($signup->isOpen(), 403);

        /*
         * Throttled to blunt account-enumeration and automated signup abuse, PER
         * ENVIRONMENT.
         *
         * Without the environment in the key, every tenant on the deployment shares one
         * bucket per IP: an office behind a single NAT address burning its ten attempts
         * on acme.cboxid.com locked out signups on every other tenant reachable from that
         * office — one customer's traffic denying another's, which is the shape of a
         * cross-tenant fault however benign the cause.
         */
        $key = 'signup|'.ThrottleScope::key().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->refuse($request, 'email',
                'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }

        RateLimiter::hit($key, 300);

        // Risk-score the signup (bot and abuse detection). Logged for review; a Reject
        // blocks only when enforcement is switched on.
        $assessment = $risk->assess($request, 'register', $request->email(), [
            'honeypot' => $request->honeypot(),
            'form_rendered_at' => $request->renderedAt(),
        ]);

        if ($risk->shouldBlock($assessment)) {
            return $this->refuse($request, 'email',
                'We could not process this request. Please try again later.');
        }

        /*
         * An elevated-but-not-reject outcome is where a CAPTCHA belongs: the scorer has
         * already decided this submission is unusual, so the friction lands on it and not
         * on everyone else. Turnstile with no keys configured verifies as true, so a
         * deployment without it keeps the unchallenged flow.
         */
        if ($risk->shouldStepUp($assessment)
            && ! $turnstile->verify($request->turnstileToken(), $request->ip())) {
            // Show the widget — this may be the first the submitter sees of it — and let
            // the failed token go. Turnstile tokens are single-use, so a retry has to
            // carry a fresh one.
            $this->inertia->flash('challenged', true);

            return $this->refuse($request, 'email',
                'Please complete the verification below, then submit again.');
        }

        if ($this->provisionsOwnIdp(app(EnvironmentContext::class))) {
            return $this->provisionAccount($request, $subjects, $links);
        }

        /*
         * The capture gate: when an administrator has flagged the email's verified domain
         * for capture AND the organization has an active SSO connection, a local password
         * account is refused — the person signs in through their organization's IdP
         * instead. It bites only for domains explicitly flagged.
         */
        if ($domains->forEmail($request->email())?->capture === true
            && ($connection = $domains->connectionForEmail($request->email())) !== null) {
            return redirect()->away(SsoStart::url($connection))
                ->with('status', 'Your organization requires signing in through SSO.');
        }

        if ($subjects->findByEmail($request->email()) !== null) {
            return $this->refuse($request, 'email', 'An account with this email already exists.');
        }

        $subject = $subjects->create($request->email(), $request->name(), $request->password());

        $organization = $orgs->create(
            new NewOrganization($request->organization(), $this->uniqueSlug($orgs, $request->organization())),
        );
        $memberships->add($organization->id, $subject->id, MembershipRole::Owner);

        // The account is usable immediately; verification confirms the address out of
        // band rather than gating the way in.
        $token = app(EmailVerification::class)->issue($subject->id, $request->email());
        Mail::to($request->email())->send(
            new EmailVerificationMail($links->route('verification.verify', $token)),
        );

        $auth->establish($request, $subject->id, ['pwd']);

        return to_route('dashboard');
    }

    /**
     * TIER 2 — the signer buys their own identity platform.
     *
     * NOTE WHAT THIS DOES NOT CREATE: the environment. A self-serve signup gets its
     * organization, its owner and its first project — but the routable, key-bearing IdP is
     * released only once the owner clicks the link in their inbox. That is what makes a
     * bot signup worthless rather than merely inconvenient.
     */
    private function provisionAccount(
        RegisterRequest $request,
        Subjects $subjects,
        MailLinks $links,
    ): RedirectResponse {
        // Subject emails are globally unique in the root — one email, one login.
        if (app(PlatformRoot::class)->run(fn () => $subjects->findByEmail($request->email())) !== null) {
            return $this->refuse($request, 'email', 'An account with this email already exists.');
        }

        try {
            $result = app(SignupProvisioner::class)->provisionPending(new TenantBlueprint(
                organizationName: $request->organization(),
                ownerEmail: $request->email(),
                ownerName: $request->name(),
                ownerPassword: $request->password(),
            ));
        } catch (QueryException $e) {
            /*
             * Two concurrent signups for the same email both clear the check above, then
             * race the unique index. The loser's whole transaction rolls back — no partial
             * state — so it gets the same friendly error rather than a 500.
             */
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return $this->refuse($request, 'email', 'An account with this email already exists.');
        }

        $token = app(PlatformRoot::class)->run(
            fn (): string => app(EmailVerification::class)->issue($result->owner->id, $request->email()),
        );

        if (is_string($token)) {
            Mail::to($request->email())->send(
                new EmailVerificationMail($links->route('verification.verify', $token)),
            );
        }

        // The buyer administers every environment they own from the root console — signed
        // straight in there, not into an environment's own domain.
        app(PlatformRoot::class)->run(
            fn () => app(PlatformAuth::class)->establish($request, $result->owner->id, ['pwd']),
        );

        return to_route('projects')->with(
            'status',
            'Account created. Confirm your email to finish setting up your first environment.',
        );
    }

    /**
     * A refusal that keeps the form.
     *
     * `withInput()` EXCEPT the password: a rejected signup must come back with the
     * organization and the address still typed, and must not come back with a password in
     * the session's flash bag.
     */
    private function refuse(RegisterRequest $request, string $field, string $message): RedirectResponse
    {
        return back()
            ->withInput($request->except(['password', 'turnstileToken']))
            ->withErrors([$field => $message]);
    }

    /**
     * True only on the platform-root environment — the one that sells IdPs — and only
     * when this is not part of an SSO flow.
     *
     * SAAS MULTI-TENANT ONLY. A self-hosted install is a single forced IdP: no base
     * domains configured means no subdomain routing and nowhere to route a new
     * environment, so signup stays a plain Tier-1 join. That one flag is the whole
     * difference between the hosted product and single-tenant self-hosting.
     */
    private function provisionsOwnIdp(EnvironmentContext $env): bool
    {
        $bases = config('cbox-id.environments.base_domains', []);

        if (! is_array($bases) || $bases === []) {
            return false;
        }

        $current = $env->current()?->environmentKey();

        if ($current === null) {
            return false;
        }

        $isRoot = Environment::query()->where('is_default', true)->whereKey($current)->exists();
        $intended = session()->get('url.intended');

        return $isRoot && ! (is_string($intended) && str_contains($intended, 'oauth'));
    }

    /** A unique-index violation across the supported drivers. */
    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 = PostgreSQL unique_violation; 23000 = MySQL integrity constraint.
        return in_array((string) $e->getCode(), ['23505', '23000'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    private function uniqueSlug(Organizations $orgs, string $organization): string
    {
        $base = Str::slug($organization) ?: 'org';
        $slug = $base;
        $n = 1;

        while ($orgs->bySlug($slug) !== null) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
