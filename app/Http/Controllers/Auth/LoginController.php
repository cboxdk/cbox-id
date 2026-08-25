<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\PageController;
use App\Http\Props\Auth\SocialProviderProps;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\SendMagicLinkRequest;
use App\Mail\MagicLinkMail;
use App\Platform\Appearance\BrandContext;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\Enums\RefusedFactor;
use App\Platform\IntendedUrl;
use App\Platform\MailLinks;
use App\Platform\PlatformAuth;
use App\Platform\RiskGuard;
use App\Platform\SamlSsoHandoff;
use App\Platform\SignupPolicy;
use App\Platform\SsoMandate;
use App\Platform\SsoMandates;
use App\Platform\SsoRefusal;
use App\Platform\SsoStart;
use App\Platform\ThrottleScope;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Organization\Contracts\Organizations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Response;

/**
 * THE SIGN-IN DOOR, and the terminal screen for every other one.
 *
 * A magic link, a passkey ceremony, a social callback, an accepted invitation — all of
 * them can end here, refused, and the sentence they are refused with has to be the one
 * that door earned. "Your password is correct" is true at exactly one of them.
 *
 * IDENTIFIER-FIRST. The address is asked for on its own, its home realm is discovered on
 * the server, and only then is a password form revealed — or not, if that realm signs in
 * somewhere else. Both steps are real requests, because the discovery is a server
 * question and answering it in the browser would mean shipping the domain map to it.
 */
final readonly class LoginController extends PageController
{
    public function show(
        Request $request,
        PlatformAuth $auth,
        Organizations $organizations,
        SignupPolicy $signup,
        BrandContext $brand,
        ?string $slug = null,
    ): Response {
        /*
         * A refusal from a door that could not explain itself. Those doors are
         * controllers and JSON endpoints: they redirect here holding only WHO was refused
         * and WHAT they proved, and the organization is named below through the one
         * lookup that knows how.
         */
        $refusal = SsoRefusal::take();

        if ($refusal !== null) {
            $this->inertia->flash('mandate', $this->mandateProps(
                app(SsoMandates::class)->forSubject($refusal->subjectId),
                $refusal->factor,
            ));
        }

        $organization = $slug === null ? null : $organizations->bySlug($slug);

        // Paints the whole page in this organization's colours, from the root view, before
        // React exists. See BrandContext.
        $brand->brand($organization);

        return $this->page('auth/login', 'Sign in', [
            'purpose' => $this->purpose(),
            // What the identifier step captured, so the password step renders with the
            // address already in it. Laravel's old-input bag is server-side; a client
            // page cannot read it, so the server states it.
            'email' => is_string($old = $request->old('email')) ? $old : '',
            'pendingLink' => $auth->pendingLink()?->label(),
            'signupOpen' => $signup->isOpen(),
            'providers' => SocialProviderProps::forOrganization($organization?->id),
        ]);
    }

    /**
     * IDENTIFIER-FIRST STEP ONE: discover the address's home realm.
     *
     * A verified domain whose organization REQUIRES single sign-on is redirected to the
     * identity provider and never sees a password form. Anything weaker falls through to
     * one, with the connection offered beside it.
     */
    public function identify(SendMagicLinkRequest $request): RedirectResponse
    {
        $offer = $this->homeRealm($request->email());

        if ($offer instanceof RedirectResponse) {
            return $offer;
        }

        $this->inertia->flash([
            'identified' => true,
            'ssoOffer' => $offer['url'],
            'ssoOfferLeads' => $offer['leads'],
        ]);

        return back()->withInput($request->only('email'));
    }

    public function login(
        LoginRequest $request,
        PlatformAuth $auth,
        RiskGuard $risk,
        SsoMandates $mandates,
        Subjects $subjects,
    ): RedirectResponse {
        /*
         * Home-realm discovery gates the password path too. A verified domain with an
         * active connection under `Require SSO` is always routed to the identity
         * provider, never authenticated locally — even if this form was reached directly.
         */
        $offer = $this->homeRealm($request->email());

        if ($offer instanceof RedirectResponse) {
            return $offer;
        }

        $key = $this->throttleKey('login', $request);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->refuse($request,
                'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }

        // Risk-score the attempt: credential stuffing, bot velocity, IP reputation, Tor.
        // Logged for review. Under enforcement a Reject hard-blocks, and an
        // elevated-but-not-reject outcome demands the emailed step-up below.
        $assessment = $risk->assess($request, 'login', $request->email());

        if ($risk->shouldBlock($assessment)) {
            return $this->refuse($request, 'We could not process this request. Please try again later.');
        }

        $result = $auth->attemptPassword(
            $request,
            $request->email(),
            $request->password(),
            $risk->shouldStepUp($assessment),
        );

        if ($result === AttemptOutcome::Invalid) {
            RateLimiter::hit($key, 60);

            return $this->refuse($request, 'Those credentials do not match our records.');
        }

        /*
         * A MANDATE, NOT A BAD PASSWORD. The credential was right and will go on being
         * refused, so this is where the form stops and the identity provider takes over.
         *
         * The limiter is neither hit nor cleared: this was not a failed guess, and no
         * session was established either. The refusal used to be an error on the email
         * field reading "those credentials do not match our records" — shown to the one
         * population that had typed them correctly.
         */
        if ($result === AttemptOutcome::SsoRequired) {
            $subject = $subjects->findByEmail($request->email());

            $this->inertia->flash('mandate', $this->mandateProps(
                $subject === null ? null : $mandates->forSubject($subject->id),
                RefusedFactor::Password,
            ));

            return back();
        }

        RateLimiter::clear($key);

        if ($result === AttemptOutcome::Mfa) {
            return to_route('mfa');
        }

        // Elevated risk on an account with no authenticator: step up with an emailed
        // one-time code before the session is established.
        if ($result === AttemptOutcome::Otp) {
            return to_route('login.step-up');
        }

        /*
         * Resume an in-flight SAML sign-on (the subject was bounced here mid-SSO); else
         * the intended URL stashed when auth bounced them here — an `/oauth/authorize`
         * they were completing, say; else the console.
         *
         * The intent has to be one a SUBJECT can serve. This host also carries the
         * environment ADMIN console, whose refusals write the same key, and sending an end
         * user to `/admin/…` bounces them straight back here with the intent rewritten.
         */
        return redirect()->to(
            app(SamlSsoHandoff::class)->resumeUrl()
                ?? IntendedUrl::pullForSubject()
                ?? route('dashboard'),
        );
    }

    public function magicLink(
        SendMagicLinkRequest $request,
        MagicLink $links,
        Subjects $subjects,
        SignupPolicy $signup,
        MailLinks $mailLinks,
    ): RedirectResponse {
        $key = $this->throttleKey('magic', $request);

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return $this->refuse($request,
                'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }

        RateLimiter::hit($key, 120);

        /*
         * Redeeming a magic link provisions the account on first sign-in, so an
         * unqualified link is a signup bypass: under invite-only or closed it would mint
         * an account for any address. A link is issued only when signup is open OR the
         * address already has an account.
         *
         * The confirmation below shows either way, so the page never reveals which — the
         * same property the password-reset page is built around.
         */
        $devUrl = null;

        if ($signup->isOpen() || $subjects->findByEmail($request->email()) !== null) {
            $url = $mailLinks->route('magic.redeem', $links->request($request->email()));

            Mail::to($request->email())->send(new MagicLinkMail($url));

            // Local installs only: a developer with no mail transport can still walk the
            // flow. It is a live credential in a page body anywhere else.
            $devUrl = app()->environment('local') ? $url : null;
        }

        $this->inertia->flash([
            'magicSentTo' => $request->email(),
            'magicUrl' => $devUrl,
        ]);

        return back()->withInput($request->only('email'));
    }

    /**
     * ROUTING AND ENFORCEMENT ARE TWO QUESTIONS.
     *
     * This used to answer both with one: any verified domain with an active connection was
     * redirected, whatever the organization had set. So `Off` and `Prefer SSO` both behaved
     * exactly like `Require SSO` for everyone on that domain — two of the three settings on
     * the auth-policy screen decided nothing, and a tenant that had deliberately left
     * enforcement off still had its people bounced to an identity provider with no way back.
     *
     * The cost of that is not theoretical. Microsoft advises against auto-acceleration for
     * exactly this reason: it hinders stronger authentication. This platform ships
     * passkeys, and somebody who had enrolled one on a verified domain could not reach it.
     *
     * So: `Require SSO` redirects and there is no local form to fall back to. Anything
     * weaker OFFERS the connection — prominently under `Prefer SSO`, quietly under `Off` —
     * and leaves the password form standing beneath it.
     *
     * @return RedirectResponse|array{url: string|null, leads: bool}
     */
    private function homeRealm(string $email): RedirectResponse|array
    {
        $connection = $this->connectionForEmail($email);

        if ($connection === null) {
            return ['url' => null, 'leads' => false];
        }

        $sso = app(AuthPolicies::class)->resolve($connection->organization_id)->sso;

        if (! $sso->allowsPasswordLogin()) {
            // A full navigation away: the destination is the identity provider's own
            // redirect endpoint, which answers with a cross-origin 302.
            return redirect()->away(SsoStart::url($connection));
        }

        return [
            'url' => SsoStart::url($connection),
            'leads' => $sso === SsoEnforcement::Preferred,
        ];
    }

    /**
     * `connectionForEmail()` is deny-by-default: it matches only a VERIFIED domain with an
     * ACTIVE connection.
     */
    private function connectionForEmail(string $email): ?Connection
    {
        if (! str_contains($email, '@')) {
            return null;
        }

        return app(DomainVerification::class)->connectionForEmail($email);
    }

    /**
     * A mandate, wherever it was refused.
     *
     * One shape for both entry points — this page's own password form and the doors that
     * redirect here — because the screen they produce has to be the same screen. Two
     * copies is how one of them would eventually stop naming the organization.
     *
     * @return array{organization: string, startUrl: string|null, reason: string}
     */
    private function mandateProps(?SsoMandate $mandate, RefusedFactor $factor): array
    {
        return [
            // Never null in practice — every door reports the refusal only after walking
            // the same memberships — but the lookup is a second read, and a screen that
            // says nothing is worse than one that names no organization.
            'organization' => $mandate === null ? 'Your organization' : $mandate->organizationName,
            'startUrl' => $mandate?->startUrl,
            'reason' => $factor->sentence(),
        ];
    }

    /**
     * Why this person is being asked to sign in, when we know.
     *
     * "Access your organization's identity console" is right for somebody who typed the
     * address, and wrong for the one case where we know they were going somewhere else: a
     * device approval. They followed a link printed by a terminal, on a phone, and the page
     * that greets them talks about a console they are not going to — a small dissonance at
     * exactly the moment they are deciding whether this link is legitimate.
     *
     * READ, never pulled: consuming the intent here would strand them on the dashboard
     * after signing in. {@see IntendedUrl} pulls it, once, after authentication.
     */
    private function purpose(): string
    {
        $intended = session()->get(IntendedUrl::KEY);
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;

        return $path === '/device'
            ? 'Sign in to approve the device that is waiting.'
            : "Welcome back. Access your organization's identity console.";
    }

    /**
     * A refusal that keeps the identifier step's answer.
     *
     * The password is dropped on the way — this door is not going to authenticate with it,
     * and a rejected credential in the session's flash bag is a credential at rest.
     */
    private function refuse(Request $request, string $message): RedirectResponse
    {
        // The identifier step stays passed: a wrong password sends nobody back to the
        // address field they already answered.
        $this->inertia->flash('identified', true);

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => $message]);
    }

    /**
     * PER ENVIRONMENT, as well as per address and per source.
     *
     * The same address exists independently in every tenant here — that is what a
     * multi-tenant identity provider is — so a key of (action, email, ip) put two different
     * people in one bucket. Failed attempts against one tenant's account throttled the
     * other's, and a lockout was a cross-tenant denial of service anybody could trigger by
     * guessing at an address they knew existed elsewhere.
     */
    private function throttleKey(string $action, Request $request): string
    {
        return $action.'|'.ThrottleScope::key().'|'
            .Str::lower((string) $request->string('email')).'|'.$request->ip();
    }
}
