<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Platform\Enums\SignInHold;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use App\Platform\SamlSsoHandoff;
use App\Platform\SignInHolds;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\SamlIdp\Contracts\SamlIdentityProvider;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Exceptions\EmailNotVerified;
use Cbox\Id\SamlIdp\Exceptions\InvalidAuthnRequest;
use Cbox\Id\SamlIdp\Exceptions\SubjectNotPermitted;
use Cbox\Id\SamlIdp\Exceptions\UnknownServiceProvider;
use Cbox\Id\SamlIdp\ValueObjects\AuthnRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * This platform's SAML 2.0 SingleSignOnService endpoint — Cbox ID acting AS the
 * IdP a downstream SP (Salesforce, Workday, AWS, …) federates to.
 *
 * The framework's thin controller resolves the signed-in subject from Laravel's
 * default guard; this app authenticates through its own session-backed
 * {@see PlatformAuth} guard instead, so the host owns the interactive step (exactly
 * as the package documents: "the is-a-user-logged-in step is the host's job").
 *
 * The protocol layer stays in the package: the request is parsed and validated
 * deny-by-default by {@see SamlIdentityProvider::parseAuthnRequest()} (unknown/
 * inactive SP, ACS mismatch, or a missing/invalid required signature are refused
 * here, before any login), and the signed Response is minted and rendered by
 * {@see SamlIdentityProvider::issueResponse()} — this controller never touches XML,
 * signing, or escaping.
 */
final class SamlIdpSsoController
{
    public function __construct(
        private readonly SamlIdentityProvider $idp,
        private readonly SamlSsoHandoff $handoff,
        private readonly SessionManager $sessions,
        private readonly Subjects $subjects,
        private readonly ServiceProviders $serviceProviders,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        // Either the SP just delivered a SAMLRequest, or this is the post-login
        // resume reading the stash left before we handed off to the login screen.
        $context = $this->handoff->resolve($request);

        if ($context === null) {
            return new Response('Missing SAMLRequest.', 400);
        }

        try {
            $authnRequest = $this->idp->parseAuthnRequest(
                $context->samlRequest,
                $context->relayState,
                $context->signature,
                $context->sigAlg,
                $context->fromRedirect,
                // The sixth argument this override used to omit — see the docblock on
                // SamlRequestContext::$rawQueryString for what that cost.
                $context->rawQueryString,
            );
        } catch (UnknownServiceProvider) {
            $this->handoff->clear();

            return new Response('Unknown or inactive SAML service provider.', 403);
        } catch (InvalidAuthnRequest $exception) {
            $this->handoff->clear();

            // A refusal the SP can be TOLD about goes back to its ACS as a signed
            // Response with a failure StatusCode — the SP logs it and renders its own
            // branded error, instead of the user sitting on our domain looking at a bare
            // 400 the SP never hears about.
            //
            // This override dropped that, which made the package's entire error path dead
            // in production: reject(), issueErrorResponse(), buildStatus(), SamlError and
            // the InvalidNameIdPolicy/RequestDenied codes. The highest-volume real refusal
            // is a Destination mismatch after a custom-domain migration — exactly the one
            // an SP admin needs to find in their own logs.
            return $this->refuse($exception);
        }

        // The host owns "who is logged in". No subject → stash the (already
        // validated) request and hand off to the login screen; it resumes here the
        // moment the subject authenticates. RelayState rides along in the stash.
        $subjectId = $this->authenticatedSubjectId($request);

        if ($subjectId === null) {
            $this->handoff->stash($context);

            return redirect()->route('login');
        }

        /*
         * WHAT THE SESSION STILL OWES. A signed assertion is a sign-in to somebody else's
         * application, so it is held exactly where the console and the authorize endpoint
         * hold the same session ({@see SignInHolds}): a forced password change, a second
         * factor to prove, a mandated one to enrol. This endpoint is not behind the auth
         * middleware, so it asked none of them, and a password-only session the console
         * was about to revoke for its second factor still single-signed into every SP.
         *
         * The request stays stashed, so the person comes back here once the hold is
         * settled: the second-factor screen resumes it itself.
         */
        $held = $this->held($request, $subjectId);

        if ($held !== null) {
            $this->handoff->stash($context);

            return $held;
        }

        $this->handoff->clear();

        // An assertion is a signed statement, and most SPs key accounts by the email in
        // it. An address nobody has confirmed is a claim, not an identity: the IdP does
        // not vouch for it. Where this SP would receive the email — as the NameID or as
        // a mapped attribute — the person confirms it first. Everywhere else the email
        // is simply never handed to the IdP (see attributesFor()).
        $subject = $this->subjects->find($subjectId);

        if ($subject !== null && ! $subject->emailVerified && $this->releasesEmail($authnRequest)) {
            return new Response('Confirm your email address before signing in to this service.', 403);
        }

        // HOW they signed in travels with the assertion. Without it the IdP falls back to
        // "unspecified", which is vague but true; with it the assertion says
        // password-protected-transport or unspecified according to what actually happened.
        // It used to say "Password" unconditionally — a false statement in a signed
        // document that relying parties act on, and one that contradicted the `acr` the
        // OIDC side derived from this very session.
        //
        // Issuance refuses on its own too, and none of those refusals may surface as a
        // 500: the package re-checks the email (the same 403 as above, should the SP's
        // mapping change between the two checks), the SP's owning organization, the SP
        // itself, and whether this request was already answered.
        try {
            $response = $this->idp->issueResponse(
                $authnRequest,
                $subjectId,
                $this->attributesFor($subject),
                $this->authenticationMethods($request),
            );
        } catch (UnknownServiceProvider) {
            return new Response('Unknown or inactive SAML service provider.', 403);
        } catch (EmailNotVerified) {
            // The person's to fix, so they are told, not the SP. The request id is not
            // burned, so the same sign-in works once the address is confirmed.
            return new Response('Confirm your email address before signing in to this service.', 403);
        } catch (SubjectNotPermitted) {
            return new Response('Your account does not have access to this service.', 403);
        } catch (InvalidAuthnRequest $exception) {
            // A replayed request, or any other refusal at issuance: answered the same
            // way a parse-time refusal is.
            return $this->refuse($exception);
        }

        // The binding brings its own content policy. This app's SecurityHeaders is
        // appended globally and would otherwise stamp `form-action 'self'` on a form
        // whose whole purpose is to post to the service provider's ACS.
        return $response->toPostBinding()->toResponse();
    }

    /**
     * Where to send a session that may not be asserted yet, or null when it may.
     *
     * A second factor is asked for the way the console asks for it: the weak session is
     * ended and the person proves the factor, which starts a session that carries it
     * ({@see PlatformAuth::challengeSession()}). The password change and the enrolment are
     * the console's own pages.
     */
    private function held(Request $request, string $subjectId): ?RedirectResponse
    {
        $sessionId = $request->session()->get(PlatformAuth::SESSION_KEY);
        $session = is_string($sessionId) ? $this->sessions->active($sessionId) : null;

        if ($session === null) {
            return null;
        }

        $hold = app(SignInHolds::class)->pending($subjectId, array_values($session->amr));

        if ($hold === null) {
            return null;
        }

        return match ($hold) {
            // The second-factor screen resumes a stashed SAML request on its own.
            SignInHold::Verification => redirect()->to(app(PlatformAuth::class)->challengeSession($request, $session)),
            // The password change ends wherever the person was going: back here.
            SignInHold::PasswordChange => tap(redirect()->route('password.change'),
                static fn () => $request->session()->put(IntendedUrl::KEY, route('sso.saml.idp.sso'))),
            SignInHold::Enrolment => redirect()->route('account')
                ->with('status', 'Your organization requires two-factor authentication. Set it up to continue.'),
        };
    }

    /**
     * A refusal the SP can be told about in SAML goes back to its ACS as a signed
     * Response with a failure StatusCode; everything else stays an opaque refusal.
     */
    private function refuse(InvalidAuthnRequest $exception): Response
    {
        $error = $exception->samlError();

        if ($error === null) {
            return new Response('SAML AuthnRequest rejected.', 400);
        }

        try {
            return $this->idp->issueErrorResponse($error)->toPostBinding()->toResponse();
        } catch (UnknownServiceProvider) {
            // Disabled between parsing and answering — no trusted ACS to deliver to.
            return new Response('Unknown or inactive SAML service provider.', 403);
        }
    }

    /**
     * The signed-in subject id from the platform session, or null. Mirrors the
     * Authenticate middleware's checks (live, non-revoked session; active subject)
     * without its redirect, so an unauthenticated hit can be handed to login with
     * the SAML context preserved rather than bounced.
     */
    private function authenticatedSubjectId(Request $request): ?string
    {
        $sessionId = $request->session()->get(PlatformAuth::SESSION_KEY);

        $session = is_string($sessionId) ? $this->sessions->active($sessionId) : null;

        if ($session === null) {
            return null;
        }

        return $this->subjects->isActive($session->user_id) ? $session->user_id : null;
    }

    /**
     * How the person signed in, from their live session.
     *
     * Read here rather than threaded down from `authenticatedSubjectId()` because that
     * method answers one question and this is another; the session lookup is a cache hit
     * by this point.
     *
     * @return list<string>
     */
    private function authenticationMethods(Request $request): array
    {
        $sessionId = $request->session()->get(PlatformAuth::SESSION_KEY);
        $session = is_string($sessionId) ? $this->sessions->active($sessionId) : null;

        if ($session === null) {
            return [];
        }

        /** @var list<string> $amr */
        $amr = array_values(array_filter($session->amr, is_string(...)));

        return $amr;
    }

    /**
     * The subject's releasable attributes, keyed by the subject field names the SP's
     * `name_id_attribute` / `attribute_mappings` reference. issueResponse projects
     * these through the SP's mapping and pins the NameID — the host only supplies the
     * source values (email, name).
     *
     * The email is a source value only once it is verified: an unconfirmed address
     * never reaches a signed assertion, whatever the SP's mapping says.
     *
     * @return array<string, string>
     */
    private function attributesFor(?Subject $subject): array
    {
        $attributes = [];

        if ($subject?->emailVerified === true && $subject->email !== null && $subject->email !== '') {
            $attributes['email'] = $subject->email;
        }

        if ($subject?->name !== null && $subject->name !== '') {
            $attributes['name'] = $subject->name;
        }

        return $attributes;
    }

    /**
     * Whether this SP's configuration would put the subject's email in the assertion:
     * as the NameID (an email or unspecified format sourced from `email`; persistent and
     * transient NameIDs are minted, never the address) or through an attribute mapping.
     */
    private function releasesEmail(AuthnRequest $request): bool
    {
        $serviceProvider = $this->serviceProviders->findActiveByEntityId($request->spEntityId);

        // Gone since parsing: issueResponse() refuses it on its own re-check.
        if ($serviceProvider === null) {
            return false;
        }

        $nameIdIsEmail = ! in_array($serviceProvider->name_id_format, [NameIdFormat::Persistent, NameIdFormat::Transient], true)
            && $serviceProvider->name_id_attribute === 'email';

        return $nameIdIsEmail || in_array('email', $serviceProvider->attribute_mappings, true);
    }
}
