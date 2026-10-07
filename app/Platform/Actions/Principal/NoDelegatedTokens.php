<?php

declare(strict_types=1);

namespace App\Platform\Actions\Principal;

/**
 * No token is a delegated token: the platform and account planes are console-only until
 * delegated OAuth management tokens exist and are bound in this one's place
 * ({@see DelegatedTokens}). Refusing everything is the safe default — those planes take
 * over accounts and run the deployment, so an unknown bearer is a 401 and never a guess.
 */
final class NoDelegatedTokens implements DelegatedTokens
{
    public function principal(string $bearer): ?PersonPrincipal
    {
        return null;
    }
}
