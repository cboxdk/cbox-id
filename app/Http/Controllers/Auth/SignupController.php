<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Requests\Auth\RegisterRequest;
use App\Mail\EmailVerificationMail;
use App\Platform\IntendedUrl;
use App\Platform\Locale\MailLocale;
use App\Platform\MailLinks;
use App\Platform\Organizations\PersonalOrganizations;
use App\Platform\PlatformAuth;
use App\Platform\RiskGuard;
use App\Platform\SignupPolicy;
use App\Platform\SsoStart;
use App\Platform\ThrottleScope;
use App\Platform\Turnstile;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Response;

/** Creates an identity; product admission and provisioning have a separate entry point. */
final readonly class SignupController extends PageController
{
    public function show(SignupPolicy $signup, Turnstile $turnstile): Response|RedirectResponse
    {
        // Self-service signup can be closed or invite-only. Would-be registrants get an
        // explanation at the sign-in door rather than a form that will refuse them.
        if (! $signup->isOpen()) {
            return to_route('login')->with('status', $signup->closedMessage());
        }

        return $this->page('auth/signup', __('auth.signup.title'), [
            'createsIdp' => false,
            'identityOnly' => $signup->identityOnly(),
            /*
             * The app an authorization is waiting to return to, when there is one — so a
             * person who pressed "Sign up" in that app is told they are creating an account
             * for it, not "setting up Cbox ID for their team", which is the operator's
             * pitch and means nothing to somebody signing up for a tax app.
             */
            'forApp' => $this->waitingApp(),
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
        PersonalOrganizations $personal,
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
         * on acme.cboxauth.com locked out signups on every other tenant reachable from that
         * office — one customer's traffic denying another's, which is the shape of a
         * cross-tenant fault however benign the cause.
         */
        $key = 'signup|'.ThrottleScope::key().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->refuse($request, 'email',
                trans_choice('auth.common.too_many_attempts', RateLimiter::availableIn($key)));
        }

        RateLimiter::hit($key, 300);

        // Risk-score the signup (bot and abuse detection) and let Radar decide — a
        // disposable address, bot velocity, the environment's own rules and lists. Recorded
        // for review; a block refuses only when the environment enforces, and with the
        // generic sentence: naming the rule would tell a bot what to change.
        $assessment = $risk->assess($request, 'register', $request->email(), [
            'honeypot' => $request->honeypot(),
            'form_rendered_at' => $request->renderedAt(),
        ]);

        if ($risk->shouldBlock($assessment)) {
            return $this->refuse($request, 'email', __('auth.common.could_not_process'));
        }

        /*
         * A CHALLENGE is where friction belongs: Radar has already decided this submission is
         * unusual, so the friction lands on it and not on everyone else.
         *
         * With Turnstile configured, that is the CAPTCHA, before anything is created. Without
         * it the challenge is the inbox: the account is created, but its first session waits
         * for the one-time code emailed to the address it was created with
         * ({@see PlatformAuth::confirmByEmail()}). Before Radar, a deployment with no
         * Turnstile keys let a challenged signup straight in — the challenge was decided and
         * then not asked.
         */
        $confirmByEmail = false;

        if ($risk->shouldStepUp($assessment)) {
            if ($turnstile->enabled()) {
                if (! $turnstile->verify($request->turnstileToken(), $request->ip())) {
                    // Show the widget — this may be the first the submitter sees of it — and
                    // let the failed token go. Turnstile tokens are single-use, so a retry has
                    // to carry a fresh one.
                    $this->inertia->flash('challenged', true);

                    return $this->refuse($request, 'email', __('auth.signup.complete_verification'));
                }
            } else {
                $confirmByEmail = true;
            }
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
                ->with('status', __('auth.signup.sso_required'));
        }

        if ($subjects->findByEmail($request->email()) !== null) {
            return $this->refuse($request, 'email', __('auth.signup.account_exists'));
        }

        $subject = $subjects->create($request->email(), $request->name(), $request->password());

        if (! $signup->identityOnly()) {
            $organization = $orgs->create(
                new NewOrganization($request->organization(), $this->uniqueSlug($orgs, $request->organization())),
            );
            $memberships->add($organization->id, $subject->id, MembershipRole::Owner);
        } elseif ($personal->enabled()) {
            // In the Cbox realm nobody is left without an organization: this form is the
            // signup that does NOT come from an invitation (an invitation creates its
            // person where it is accepted), so the person gets one of their own.
            $personal->ensureFor($subject);
        }

        // The account is usable immediately; verification confirms the address out of
        // band rather than gating the way in.
        $token = app(EmailVerification::class)->issue($subject->id, $request->email());
        Mail::to($request->email())->locale(app(MailLocale::class)->forRecipient())->queue(
            new EmailVerificationMail($links->route('verification.verify', $token)),
        );

        if ($confirmByEmail) {
            $auth->confirmByEmail($request, $subject->id, $request->email());

            return to_route('login.step-up');
        }

        $auth->establish($request, $subject->id, ['pwd']);

        /*
         * BACK INTO THE APP when an authorization sent them here — `prompt=create`, or the
         * "Create an account" link under an app's sign-in. The resumed request binds the
         * grant to the verified person. Team selection and product admission happen in
         * the consuming service after identity creation.
         */
        return redirect()->to(IntendedUrl::pullForSubject() ?? route($signup->identityOnly() ? 'account' : 'dashboard'));
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
     * The name of the app whose authorization request sent the person here, or null.
     *
     * Read from the resume URL the authorize endpoint stashed, and looked up through the
     * registry in THIS environment: a client id copied from somewhere else resolves to
     * nothing, so the page can never be made to name an app that is not registered here.
     */
    private function waitingApp(): ?string
    {
        $intended = session()->get(IntendedUrl::KEY);

        if (! is_string($intended) || parse_url($intended, PHP_URL_PATH) !== '/oauth/authorize') {
            return null;
        }

        parse_str((string) parse_url($intended, PHP_URL_QUERY), $query);
        $clientId = $query['client_id'] ?? null;

        return is_string($clientId) && $clientId !== ''
            ? app(ClientRegistry::class)->byClientId($clientId)?->name
            : null;
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
