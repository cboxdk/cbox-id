<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

/**
 * The console's call to the issuer's token endpoint: an authorization code in, the ID
 * token out. A contract so the transport can be swapped; {@see HttpIssuerTokens} is the one.
 */
interface IssuerTokens
{
    /**
     * @throws ConsoleSignInFailed when the issuer refuses the code or cannot be reached
     */
    public function exchange(string $clientId, string $clientSecret, string $code, string $codeVerifier, string $redirectUri): string;
}
