<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Http\Request;

/**
 * Carries the in-flight SAML `AuthnRequest` across the host's interactive login.
 *
 * When a downstream SP hits the IdP SingleSignOnService endpoint and no subject is
 * signed in, the request context (the SAMLRequest and, for a signed request, its
 * detached signature) is stashed in the session so the flow can resume — and the
 * assertion be minted — the moment the subject authenticates. RelayState is carried
 * untouched so the SP round-trips its own opaque state.
 *
 * The stash is the ONLY place a post-login resume reads the request from: the
 * HTTP-POST binding delivers the SAMLRequest in the body, which the login redirect
 * cannot preserve, so the session is authoritative on the way back.
 */
final class SamlSsoHandoff
{
    private const PENDING_KEY = 'cbox.saml_idp_pending';

    /**
     * Resolve the SAML request context for this hit: the live request parameters
     * when the SP just delivered one, otherwise the stash left for a post-login
     * resume. Returns null when neither is present (a bare, contextless hit).
     *
     * @return array{samlRequest: string, relayState: ?string, signature: ?string, sigAlg: ?string, fromRedirect: bool}|null
     */
    public function resolve(Request $request): ?array
    {
        $samlRequest = $this->str($request->input('SAMLRequest'));

        if ($samlRequest === null) {
            return $this->pending();
        }

        return [
            'samlRequest' => $samlRequest,
            'relayState' => $this->str($request->input('RelayState')),
            'signature' => $this->str($request->input('Signature')),
            'sigAlg' => $this->str($request->input('SigAlg')),
            // Redirect binding is a GET (base64+DEFLATE, detached signature); the
            // POST binding is a POST (base64 only, embedded XML-DSig).
            'fromRedirect' => $request->isMethod('get'),
        ];
    }

    /**
     * @param  array{samlRequest: string, relayState: ?string, signature: ?string, sigAlg: ?string, fromRedirect: bool}  $context
     */
    public function stash(array $context): void
    {
        session()->put(self::PENDING_KEY, $context);
    }

    public function clear(): void
    {
        session()->forget(self::PENDING_KEY);
    }

    public function hasPending(): bool
    {
        return $this->pending() !== null;
    }

    /**
     * Where a just-authenticated subject should be sent to resume an in-flight SAML
     * sign-on, or null when there is none — so the caller falls back to its default
     * post-login destination.
     */
    public function resumeUrl(): ?string
    {
        return $this->hasPending() ? route('sso.saml.idp.sso') : null;
    }

    /**
     * The stashed context, re-narrowed from the loosely-typed session bag so a
     * malformed or partial stash is treated as absent rather than trusted.
     *
     * @return array{samlRequest: string, relayState: ?string, signature: ?string, sigAlg: ?string, fromRedirect: bool}|null
     */
    private function pending(): ?array
    {
        $stash = session()->get(self::PENDING_KEY);

        if (! is_array($stash)) {
            return null;
        }

        $samlRequest = $this->str($stash['samlRequest'] ?? null);

        if ($samlRequest === null) {
            return null;
        }

        return [
            'samlRequest' => $samlRequest,
            'relayState' => $this->str($stash['relayState'] ?? null),
            'signature' => $this->str($stash['signature'] ?? null),
            'sigAlg' => $this->str($stash['sigAlg'] ?? null),
            'fromRedirect' => ($stash['fromRedirect'] ?? true) === true,
        ];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
