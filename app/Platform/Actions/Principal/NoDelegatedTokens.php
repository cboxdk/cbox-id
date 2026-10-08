<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

use Illuminate\Http\Request;

/**
 * No token is a delegated token: the platform and account planes console-only. Not the
 * binding any more ({@see OAuthDelegatedTokens} is) — kept as the closed alternative a
 * deployment can bind to switch both REST doors off, and as what refusing everything
 * looks like: those planes take over accounts and run the deployment, so an unknown
 * bearer is a 401 and never a guess.
 */
final class NoDelegatedTokens implements DelegatedTokens
{
    public function principal(Request $request): ?PersonPrincipal
    {
        return null;
    }
}
