<?php

declare(strict_types=1);

namespace App\Platform\Sso;

use Cbox\Id\Federation\AppleClientSecret;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Enums\TokenEndpointAuthMethod;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\OidcClient;
use Cbox\Id\Federation\Support\SafeFederationUrl;
use Illuminate\Support\Facades\Http;

/**
 * PKCE (RFC 7636) on OUR side of an OpenID Connect federation, for hand-configured
 * connections.
 *
 * The framework's relying party sends `state` and `nonce` and no code challenge. Most
 * providers accept that from a confidential client — and Cbox ID itself does not: its
 * `/oauth/authorize` requires a challenge from every client. So one Cbox ID environment
 * could not sign in through another (the platform root through the `cbox` environment)
 * until this side sent one.
 *
 * STATELESS. The verifier is an HMAC of the connection and the flow's `state` under the
 * application key: the redirect leg and the callback derive the same value without
 * storing anything, and somebody who sees the authorization request (the state is in its
 * URL) cannot derive it without the key. The state is single-use, so each verifier is too.
 *
 * Hand-configured connections only (`provider` null): catalogue providers keep exactly
 * the request they were tested with. A provider that does not implement PKCE ignores the
 * extra parameters, as RFC 6749 §3.1 has it ignore any it does not know.
 */
final readonly class OidcPkce
{
    public function __construct(
        private Connections $connections,
        private AppleClientSecret $signedSecrets,
    ) {}

    public function applies(Connection $connection): bool
    {
        return $connection->type === ConnectionType::Oidc && $connection->provider === null;
    }

    public function verifier(Connection $connection, string $state): string
    {
        $key = config('app.key');
        $key = is_string($key) ? $key : '';

        return self::base64url(hash_hmac('sha256', 'oidc-pkce|'.$connection->id.'|'.$state, $key, true))
            .self::base64url(hash_hmac('sha256', 'oidc-pkce-2|'.$connection->id.'|'.$state, $key, true));
    }

    public function challenge(Connection $connection, string $state): string
    {
        return self::base64url(hash('sha256', $this->verifier($connection, $state), true));
    }

    /** $authorizeUrl with the S256 challenge for its own `state` added. */
    public function withChallenge(Connection $connection, string $authorizeUrl): string
    {
        parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $query);
        $state = $query['state'] ?? null;

        if (! is_string($state) || $state === '') {
            return $authorizeUrl;
        }

        return $authorizeUrl.(str_contains($authorizeUrl, '?') ? '&' : '?').http_build_query([
            'code_challenge' => $this->challenge($connection, $state),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * The framework's token exchange ({@see OidcClient::exchange()}),
     * with the verifier: the same SSRF pinning, no redirects, one client authentication
     * method per request. Returns the id_token.
     */
    public function exchange(Connection $connection, string $code, string $redirectUri, string $state): string
    {
        $config = $this->connections->oidcConfig($connection);
        $endpoint = $config->requireField($config->tokenEndpoint, 'token_endpoint');

        try {
            $pinned = SafeFederationUrl::pinnedOptions($endpoint);
        } catch (UnsafeFederationUrl $e) {
            throw InvalidAssertion::make('token endpoint blocked: '.$e->getMessage());
        }

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $this->verifier($connection, $state),
        ];
        $headers = [];

        $credential = $config->signingCredential;
        $secret = $credential === null
            ? $config->requireField($config->clientSecret, 'client_secret')
            : $this->signedSecrets->mint($connection->id, $credential->issuerId, $credential->keyId, $credential->privateKey, $config->clientId);

        if ($credential === null && $config->tokenEndpointAuthMethod === TokenEndpointAuthMethod::ClientSecretBasic) {
            $headers['Authorization'] = TokenEndpointAuthMethod::basicCredentials($config->clientId, $secret);
        } else {
            $form['client_id'] = $config->clientId;
            $form['client_secret'] = $secret;
        }

        $response = Http::asForm()
            ->withOptions($pinned)
            ->withoutRedirecting()
            ->withHeaders($headers)
            ->timeout(10)
            ->post($endpoint, $form);

        if (! $response->successful()) {
            throw InvalidAssertion::make('token exchange failed');
        }

        $idToken = $response->json('id_token');

        if (! is_string($idToken) || $idToken === '') {
            throw InvalidAssertion::make('token response contained no id_token');
        }

        return $idToken;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
