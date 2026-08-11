<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso;

use App\Platform\PlatformAuth;
use App\Platform\SamlSsoHandoff;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\SamlIdp\Contracts\SamlIdentityProvider;
use Cbox\Id\SamlIdp\Exceptions\InvalidAuthnRequest;
use Cbox\Id\SamlIdp\Exceptions\UnknownServiceProvider;
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

        // The host owns "who is logged in". No subject → stash the (already
        // validated) request and hand off to the login screen; it resumes here the
        // moment the subject authenticates. RelayState rides along in the stash.
        $subjectId = $this->authenticatedSubjectId($request);

        if ($subjectId === null) {
            $this->handoff->stash($context);

            return redirect()->route('login');
        }

        $this->handoff->clear();

        // HOW they signed in travels with the assertion. Without it the IdP falls back to
        // "unspecified", which is vague but true; with it the assertion says
        // password-protected-transport or unspecified according to what actually happened.
        // It used to say "Password" unconditionally — a false statement in a signed
        // document that relying parties act on, and one that contradicted the `acr` the
        // OIDC side derived from this very session.
        $response = $this->idp->issueResponse(
            $authnRequest,
            $subjectId,
            $this->attributesFor($subjectId),
            $this->authenticationMethods($request),
        );

        // The binding brings its own content policy. This app's SecurityHeaders is
        // appended globally and would otherwise stamp `form-action 'self'` on a form
        // whose whole purpose is to post to the service provider's ACS.
        return $response->toPostBinding()->toResponse();
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
     * @return array<string, string>
     */
    private function attributesFor(string $subjectId): array
    {
        $subject = $this->subjects->find($subjectId);

        $attributes = [];

        if ($subject?->email !== null && $subject->email !== '') {
            $attributes['email'] = $subject->email;
        }

        if ($subject?->name !== null && $subject->name !== '') {
            $attributes['name'] = $subject->name;
        }

        return $attributes;
    }
}
