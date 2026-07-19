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
            );
        } catch (UnknownServiceProvider) {
            $this->handoff->clear();

            return new Response('Unknown or inactive SAML service provider.', 403);
        } catch (InvalidAuthnRequest) {
            $this->handoff->clear();

            return new Response('SAML AuthnRequest rejected.', 400);
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

        $response = $this->idp->issueResponse($authnRequest, $subjectId, $this->attributesFor($subjectId));

        return new Response($response->toPostForm(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
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
