<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Dpop\DpopResourceGuard;
use Cbox\Id\OAuthServer\Exceptions\InvalidDpopProof;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resource-server guard for the customer-facing REST API. It authenticates the
 * bearer (or DPoP-bound) access token, requires a specific OAuth scope, enforces
 * DPoP sender-constraining when the token carries it, and stashes the verified
 * {@see Introspection} on the request as
 * `cbox_token` for the controller to read (e.g. the caller's `clientId`).
 *
 * Deny-by-default: no token, an inactive token, a missing scope, or a bad DPoP
 * proof each short-circuits with the appropriate RFC 6750 / 9449 challenge.
 */
final class RequireScope
{
    public function __construct(
        private readonly DpopResourceGuard $dpop,
        private readonly TokenIntrospector $introspector,
        private readonly IssuerResolver $issuers,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $bearer = $this->dpop->bearer($request);

        if (! is_string($bearer) || $bearer === '') {
            return $this->challenge('invalid_request', 'An access token is required.', 401);
        }

        $token = $this->introspector->introspect($bearer);

        if (! $token->active || $token->clientId === null) {
            return $this->challenge('invalid_token', 'The access token is invalid or expired.', 401);
        }

        // A token minted for a specific RFC 8707 resource must not be replayable
        // against this first-party API: accept only tokens audienced for this issuer.
        if (! $token->isAudience($this->issuers->issuer())) {
            return $this->challenge('invalid_token', 'The access token was not issued for this API.', 401);
        }

        if (! $token->hasScope($scope)) {
            return $this->challenge('insufficient_scope', "This endpoint requires the {$scope} scope.", 403, $scope);
        }

        try {
            $this->dpop->enforce($request, $bearer, $token);
        } catch (InvalidDpopProof $e) {
            return $this->challenge('invalid_token', $e->getMessage(), 401);
        }

        $request->attributes->set('cbox_token', $token);

        return $next($request);
    }

    private function challenge(string $error, string $description, int $status, ?string $scope = null): JsonResponse
    {
        $params = "error=\"{$error}\", error_description=\"{$description}\"";
        if ($scope !== null) {
            $params .= ", scope=\"{$scope}\"";
        }

        return new JsonResponse(
            ['error' => $error, 'error_description' => $description],
            $status,
            ['WWW-Authenticate' => 'Bearer '.$params],
        );
    }
}
