<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

use App\Platform\Surfaces\Surfaces;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * `POST {login host}/oauth/token`, as the confidential client the console is.
 *
 * Over HTTP although both hosts are this deployment: the token endpoint is where a code is
 * redeemed once, PKCE is checked, the client is authenticated and the ID token is signed,
 * and a second copy of that in the console would be a second place to get it wrong.
 */
final readonly class HttpIssuerTokens implements IssuerTokens
{
    public function __construct(private Surfaces $surfaces) {}

    public function exchange(string $clientId, string $clientSecret, string $code, string $codeVerifier, string $redirectUri): string
    {
        try {
            $response = Http::asForm()
                ->withBasicAuth($clientId, $clientSecret)
                ->acceptJson()
                ->withoutRedirecting()
                ->timeout(10)
                ->post($this->surfaces->loginUrl('/oauth/token'), [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'code_verifier' => $codeVerifier,
                    'redirect_uri' => $redirectUri,
                ]);
        } catch (ConnectionException) {
            throw ConsoleSignInFailed::because('issuer_unreachable');
        }

        $idToken = $response->json('id_token');

        if (! $response->successful() || ! is_string($idToken) || $idToken === '') {
            $error = $response->json('error');

            throw ConsoleSignInFailed::because(is_string($error) && $error !== '' ? 'token_'.$error : 'token_exchange_failed');
        }

        return $idToken;
    }
}
