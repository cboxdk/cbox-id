<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Http\Request;

/**
 * An in-flight SAML `AuthnRequest` and everything needed to verify and resume it:
 * the request itself, the SP's opaque RelayState, the detached redirect-binding
 * signature (or null for the POST binding), and which binding delivered it. Carried
 * as one typed object across the host's interactive login instead of a loose array,
 * so the controller reads `$context->samlRequest` rather than a stringly-keyed bag.
 *
 * The session stores the flat {@see toSession()} array (a serialization boundary);
 * {@see fromSession()} re-narrows it, treating a malformed or partial stash as absent
 * rather than trusting it.
 */
final readonly class SamlRequestContext
{
    public function __construct(
        public string $samlRequest,
        public ?string $relayState,
        public ?string $signature,
        public ?string $sigAlg,
        public bool $fromRedirect,
    ) {}

    /**
     * The context carried on a live request, or null when it has no SAMLRequest.
     */
    public static function fromRequest(Request $request): ?self
    {
        $samlRequest = self::str($request->input('SAMLRequest'));

        if ($samlRequest === null) {
            return null;
        }

        return new self(
            samlRequest: $samlRequest,
            relayState: self::str($request->input('RelayState')),
            signature: self::str($request->input('Signature')),
            sigAlg: self::str($request->input('SigAlg')),
            // Redirect binding is a GET (base64+DEFLATE, detached signature); the
            // POST binding is a POST (base64 only, embedded XML-DSig).
            fromRedirect: $request->isMethod('get'),
        );
    }

    /**
     * Re-narrow a stashed session value, or null when it is missing/malformed.
     */
    public static function fromSession(mixed $stash): ?self
    {
        if (! is_array($stash)) {
            return null;
        }

        $samlRequest = self::str($stash['samlRequest'] ?? null);

        if ($samlRequest === null) {
            return null;
        }

        return new self(
            samlRequest: $samlRequest,
            relayState: self::str($stash['relayState'] ?? null),
            signature: self::str($stash['signature'] ?? null),
            sigAlg: self::str($stash['sigAlg'] ?? null),
            fromRedirect: ($stash['fromRedirect'] ?? true) === true,
        );
    }

    /**
     * @return array{samlRequest: string, relayState: ?string, signature: ?string, sigAlg: ?string, fromRedirect: bool}
     */
    public function toSession(): array
    {
        return [
            'samlRequest' => $this->samlRequest,
            'relayState' => $this->relayState,
            'signature' => $this->signature,
            'sigAlg' => $this->sigAlg,
            'fromRedirect' => $this->fromRedirect,
        ];
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
