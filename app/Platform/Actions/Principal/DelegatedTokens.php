<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Http\Middleware\AuthenticateDelegatedApi;
use Illuminate\Http\Request;

/**
 * Turns a bearer token a PERSON delegated into the principal it speaks for — the seam the
 * platform and account planes' REST door ({@see AuthenticateDelegatedApi}) reads.
 *
 * Bound to {@see OAuthDelegatedTokens}: a token the platform root issued for its `/mcp`
 * (a workspace member, or an operator) wherever the request resolved no environment or
 * resolved the root, and a token an environment issued to one of its own subjects on that
 * environment's host. {@see NoDelegatedTokens} — which recognises nothing — remains the
 * closed alternative for a deployment that wants both planes console-only.
 *
 * It takes the REQUEST, not the bearer string: a DPoP-bound token is good only with a
 * proof for this very request (method and URL), so a resolver handed a bare string could
 * not refuse a token presented by somebody who copied it.
 *
 * A management key (`cbid_env_…`, `cbid_ws_…`) must never resolve here: it speaks for a
 * customer's environment or workspace, never for a person.
 */
interface DelegatedTokens
{
    /**
     * The person behind the request's bearer — an {@see OperatorPrincipal} when they are an
     * active platform operator — or null when it is no delegated token this deployment
     * issued (unknown, expired, revoked, another audience, a management key).
     */
    public function principal(Request $request): ?PersonPrincipal;
}
