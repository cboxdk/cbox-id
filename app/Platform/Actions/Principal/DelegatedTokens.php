<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Http\Middleware\AuthenticateDelegatedApi;

/**
 * Turns a bearer token a PERSON delegated into the principal it speaks for — the seam the
 * platform and account planes' REST door ({@see AuthenticateDelegatedApi}) reads.
 *
 * Bound to {@see NoDelegatedTokens} — which recognises nothing, so those planes answer 401
 * to every bearer — until delegated OAuth management tokens exist. Binding the real
 * resolver is the whole change that opens them: the routes, the scopes, the OpenAPI
 * documents and every action behind them are already in place, and already run from the
 * console as the same person.
 *
 * A management key (`cbid_env_…`, `cbid_ws_…`) must never resolve here: it speaks for a
 * customer's environment or workspace, never for a person.
 */
interface DelegatedTokens
{
    /**
     * The person behind $bearer — an {@see OperatorPrincipal} when they are an active
     * platform operator — or null when it is no delegated token this deployment issued
     * (unknown, expired, revoked, a management key).
     */
    public function principal(string $bearer): ?PersonPrincipal;
}
