<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Platform\OAuth\MachineTokenAuthority;
use Cbox\Id\Api\Http\Controllers\DecisionController as FrameworkDecisionController;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Dpop\DpopResourceGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Require real machine issuance before the framework's service decision branch. */
final class DecisionController extends FrameworkDecisionController
{
    public function __invoke(Request $request): JsonResponse
    {
        $bearer = app(DpopResourceGuard::class)->bearer($request);
        if (is_string($bearer) && $bearer !== '') {
            $token = app(TokenIntrospector::class)->introspect($bearer);
            if ($token->active && $token->clientId !== null && $token->subject === $token->clientId) {
                // Opaque user and client identifiers can collide. Neither equality nor
                // an inherited machine scope proves client_credentials issuance.
                if (! MachineTokenAuthority::issued($token)) {
                    return new JsonResponse(['error' => 'machine_token_required', 'error_description' => 'Service decisions require a client-credentials token.'], 403);
                }
            }
        }

        // Keep audience, DPoP, scope, tenant ownership and human self-decision rules.
        return parent::__invoke($request);
    }
}
