<?php

declare(strict_types=1);

namespace App\Platform\Social;

use Cbox\Id\Federation\Contracts\AssertionValidator;
use Cbox\Id\Federation\Contracts\OidcRelyingParty;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\InvalidAssertion;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\OAuth2Client;
use Cbox\Id\Federation\OidcDiscovery;
use Cbox\Id\Federation\ValueObjects\OAuth2ConnectionConfig;
use Cbox\Id\Federation\ValueObjects\OidcConnectionConfig;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Illuminate\Support\Facades\Cache;

/**
 * Operator-level social sign-in, driven through the SAME federation clients as every
 * tenant connection.
 *
 * This class exists to answer one question — where does an operator's configuration come
 * from — and to answer nothing else. It performs no token exchange, verifies no
 * signature, and pins no algorithm: all of that is the framework's
 * {@see OidcRelyingParty}, {@see AssertionValidator} and {@see OAuth2Client}, used here
 * exactly as the tenant-facing SSO controllers use them. That is the whole point. A
 * second implementation of the same protocol is a second place every future fix has to
 * land, and the one it replaced had none of the reasoning behind it: no SSRF pinning on
 * its outbound calls, no algorithm allow-list, and its own idea of what an email address
 * proves.
 *
 * ## Why a Connection object appears here, and why it is never a row
 *
 * The framework's OIDC pair takes a {@see Connection} because that is how a TENANT's
 * config reaches it — sealed, per organization, edited from a console. An operator's
 * credentials are not that. They are deployment configuration: environment-driven,
 * identical for every request, owned by whoever runs the deployment. Forcing them into
 * the connections table to reuse the code path would put deployment config under tenant
 * scoping and give it a lifecycle (drafts, activation, per-org ownership) it has no use
 * for.
 *
 * So the config travels in the shape the clients already accept, and stops there. The
 * object built below is never saved, never queried, and has no organization or
 * environment — {@see Connection}'s environment scoping only engages on save and on
 * query, so an instance that does neither never touches the tenant boundary. Sealing it
 * is not ceremony either: it is what makes the operator's secret reach the client
 * through the very same parse-and-validate path a tenant's does, rather than through a
 * second one written for this case.
 */
class OperatorSocialFlow
{
    /**
     * Discovery documents change about as often as a provider changes its endpoints, and
     * a miss costs an outbound request in the middle of somebody's sign-in.
     */
    private const DISCOVERY_TTL_SECONDS = 86400;

    public function __construct(
        private readonly OidcRelyingParty $oidc,
        private readonly AssertionValidator $validator,
        private readonly OAuth2Client $oauth2,
        private readonly OidcDiscovery $discovery,
        private readonly SecretBox $secretBox,
    ) {}

    /**
     * The URL the browser is sent to. `$nonce` is ignored for a provider that speaks
     * plain OAuth 2.0, which has no `id_token` to bind one to.
     *
     * @throws InvalidAssertion when the provider's endpoints cannot be resolved
     */
    public function authorizeUrl(OperatorProvider $provider, string $redirectUri, string $state, string $nonce): string
    {
        if ($provider->isOidc()) {
            return $this->oidc->authorizeUrl($this->connection($provider), $redirectUri, $state, $nonce);
        }

        return $this->oauth2->authorizeUrl(
            $provider->template,
            $this->oauth2Config($provider),
            $redirectUri,
            $state,
        );
    }

    /**
     * Complete the flow and return the identity, in the shape the platform stores.
     *
     * @throws InvalidAssertion when the exchange, the signature, or the nonce fails
     */
    public function principal(OperatorProvider $provider, string $code, string $redirectUri, string $nonce): FederatedPrincipal
    {
        $resolved = $provider->isOidc()
            ? $this->oidcPrincipal($provider, $code, $redirectUri, $nonce)
            : $this->oauth2->principal(
                $provider->template,
                $this->oauth2Config($provider),
                $code,
                $redirectUri,
            );

        // Re-stamped rather than passed through, for two reasons that both outlive this
        // change. The provider string is what a returning user is MATCHED on, and the
        // clients label their own paths (`oidc`, `oauth2:github`) — storing those would
        // orphan every identity linked back when the old driver wrote `social:github`.
        // connection id is deliberately dropped: there is no connection, and a principal
        // carrying an id that resolves to nothing is worse than one carrying none.
        return new FederatedPrincipal(
            provider: $provider->identityProvider(),
            subject: $resolved->subject,
            email: $resolved->email,
            name: $resolved->name,
            connectionId: null,
            // The claim set is deliberately NOT carried. This principal can be held in
            // the session by the pending-link flow, and a full id_token's claims are both
            // more personal data than that needs and more than a session should hold.
            raw: ['provider' => $provider->key()],
        );
    }

    /**
     * @throws InvalidAssertion
     */
    private function oidcPrincipal(OperatorProvider $provider, string $code, string $redirectUri, string $nonce): FederatedPrincipal
    {
        $connection = $this->connection($provider);

        $idToken = $this->oidc->exchangeCode($connection, $code, $redirectUri);
        $principal = $this->validator->validate($connection, $idToken);

        // OIDC Core §3.1.3.7 (11): the nonce binds the token to THIS browser's
        // authorization request. Without it a valid id_token obtained elsewhere could be
        // replayed into this callback. The framework's validator checks the signature,
        // issuer and audience but leaves the nonce to the caller that issued it — the
        // tenant callback checks it the same way, at the same point.
        $returned = $principal->raw['nonce'] ?? null;

        if (! is_string($returned) || ! hash_equals($nonce, $returned)) {
            throw InvalidAssertion::make('nonce mismatch');
        }

        return $principal;
    }

    /**
     * The operator's config, in the shape the OIDC client and validator read.
     *
     * @throws InvalidAssertion
     */
    private function connection(OperatorProvider $provider): Connection
    {
        $issuer = $provider->issuer()
            ?? throw InvalidAssertion::make($provider->key().' has no resolvable issuer');

        $discovered = $this->discovered($issuer);

        $config = new OidcConnectionConfig(
            issuer: $issuer,
            clientId: $provider->clientId,
            clientSecret: $provider->clientSecret,
            authorizationEndpoint: $this->required($discovered, 'authorization_endpoint', $issuer),
            tokenEndpoint: $this->required($discovered, 'token_endpoint', $issuer),
            // Preferred over pasted keys: a signing-key rotation is picked up on its own.
            jwksUri: $discovered['jwks_uri'] ?? null,
            scopes: $provider->template->scopes,
        );

        $connection = new Connection;
        $connection->id = 'operator-'.$provider->key();
        $connection->type = ConnectionType::Oidc;
        $connection->status = ConnectionStatus::Active;
        $connection->config_encrypted = $this->secretBox->seal(
            json_encode($config->toArray(), JSON_THROW_ON_ERROR),
            $connection->secretContext(),
        );

        return $connection;
    }

    private function oauth2Config(OperatorProvider $provider): OAuth2ConnectionConfig
    {
        // fromArray() refuses a key that names an OIDC provider, so an entry that changed
        // protocol in the catalogue cannot be driven down the unsigned path by accident.
        return OAuth2ConnectionConfig::fromArray([
            'provider' => $provider->key(),
            'client_id' => $provider->clientId,
            'client_secret' => $provider->clientSecret,
        ]);
    }

    /**
     * The issuer's discovery document, cached.
     *
     * Fetched through {@see OidcDiscovery}, which resolves it behind the same DNS-pinned
     * SSRF gate as every other outbound federation call and rejects a document whose
     * advertised issuer does not match the one asked for (OIDC Discovery §4.3) — so a
     * hostile document cannot move the endpoints while still claiming a trusted issuer.
     *
     * Cached as primitives, not as an object: a cache that has to deserialize a class is
     * a cache that breaks on the day that class gains a property.
     *
     * @return array<string, string>
     */
    private function discovered(string $issuer): array
    {
        return Cache::remember(
            'operator-oidc-discovery:'.hash('sha256', $issuer),
            self::DISCOVERY_TTL_SECONDS,
            fn (): array => $this->discovery->fromIssuer($issuer)->toConfig(),
        );
    }

    /**
     * @param  array<string, string>  $discovered
     *
     * @throws InvalidAssertion
     */
    private function required(array $discovered, string $key, string $issuer): string
    {
        $value = $discovered[$key] ?? null;

        if ($value === null || $value === '') {
            throw InvalidAssertion::make("discovery for {$issuer} carried no {$key}");
        }

        return $value;
    }
}
