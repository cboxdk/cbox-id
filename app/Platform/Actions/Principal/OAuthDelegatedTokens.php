<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use App\Http\Middleware\AuthenticateDelegatedApi;
use App\Platform\OAuth\DelegatedAccess;
use App\Platform\OAuth\RootDelegatedAccess;
use Illuminate\Http\Request;

/**
 * The real {@see DelegatedTokens}: which person a bearer on the account or platform plane
 * ({@see AuthenticateDelegatedApi}) stands for, read by the door that issued it.
 *
 * WHICH ISSUER, BY WHERE THE REQUEST IS. Every environment is its own identity store, and a
 * person's account lives in the one they are a subject of:
 *
 *  - where the request resolved NO environment — the platform plane, which is the
 *    deployment above every environment — or resolved the PLATFORM ROOT, the token must be
 *    the root's ({@see RootDelegatedAccess}): a workspace member's or an operator's, for
 *    their root account and, when they are one, the operator API;
 *  - on any other environment's host, the token must be that environment's own
 *    ({@see DelegatedAccess}), for that subject's own account there.
 *
 * So `/api/v1/me` answers on both kinds of host, and on each for the people who belong to
 * it — the console's My account pages do exactly that — and a token is never read against
 * an issuer other than the one that signed it.
 */
final readonly class OAuthDelegatedTokens implements DelegatedTokens
{
    public function __construct(
        private RootDelegatedAccess $root,
        private DelegatedAccess $environment,
    ) {}

    public function principal(Request $request): ?PersonPrincipal
    {
        return $this->root->inRootContext()
            ? $this->root->principal($request)
            : $this->environment->principal($request);
    }
}
