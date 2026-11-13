<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use Cbox\Id\OAuthServer\Contracts\AuthenticationAwareTokenIssuer;
use Cbox\Id\OAuthServer\Contracts\AuthorizationDetailsTokenIssuer;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Exceptions\InvalidGrant;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ActingParty;
use Cbox\Id\OAuthServer\ValueObjects\AuthenticationEvent;
use Cbox\Id\OAuthServer\ValueObjects\AuthorizationDetails;
use Cbox\Id\OAuthServer\ValueObjects\IssuedToken;
use DateTimeInterface;

/**
 * No token is minted for a closed organization, by any grant.
 *
 * Suspending or archiving an organization refused its members at the sign-in doors, the
 * consent page and the console, and the token endpoint never asked: a refresh token kept
 * minting access tokens carrying `org` and `org_role` for it, the organization's own
 * backend kept getting `client_credentials` tokens (and with them its vault), a token
 * exchange carried the `org` across, and an open support session kept acting as a member.
 * Every one of those reaches the issuer, so the issuer is where it is asked, once.
 *
 * Asked at issuance rather than revoked at suspension, so reactivating the organization
 * restores every grant without anybody signing in again. The refresh grant asks the same
 * question earlier, before it consumes the presented token ({@see WithdrawingRefreshTokens}),
 * so a refusal here costs a client nothing it could use after reactivation.
 *
 * InvalidGrant is the issuer's own refusal: the token endpoint answers it as
 * `invalid_grant`, on every grant.
 */
final readonly class StandingTokenIssuer implements AuthenticationAwareTokenIssuer, AuthorizationDetailsTokenIssuer
{
    public function __construct(private TokenIssuer $inner) {}

    public function issueClientCredentials(Client $client, array $scopes = [], ?string $resource = null, ?string $dpopJkt = null): IssuedToken
    {
        // The client's own organization is the one its token names.
        $this->assertOpen($client->organization_id);

        return $this->inner->issueClientCredentials($client, $scopes, $resource, $dpopJkt);
    }

    public function issueForUser(Client $client, string $userId, ?string $organizationId, array $scopes = [], ?string $resource = null, ?string $dpopJkt = null): IssuedToken
    {
        $this->assertOpen($organizationId);

        return $this->inner->issueForUser($client, $userId, $organizationId, $scopes, $resource, $dpopJkt);
    }

    public function issueActing(Client $client, string $userId, ?string $organizationId, array $scopes, ActingParty $actor, DateTimeInterface $notAfter, ?string $resource = null, ?string $dpopJkt = null): IssuedToken
    {
        $this->assertOpen($organizationId);

        return $this->inner->issueActing($client, $userId, $organizationId, $scopes, $actor, $notAfter, $resource, $dpopJkt);
    }

    public function issueForAuthenticatedUser(Client $client, string $userId, ?string $organizationId, array $scopes, AuthenticationEvent $authentication, ?string $resource = null, ?string $dpopJkt = null): IssuedToken
    {
        $this->assertOpen($organizationId);

        return $this->inner instanceof AuthenticationAwareTokenIssuer
            ? $this->inner->issueForAuthenticatedUser($client, $userId, $organizationId, $scopes, $authentication, $resource, $dpopJkt)
            : $this->inner->issueForUser($client, $userId, $organizationId, $scopes, $resource, $dpopJkt);
    }

    public function issueForExchange(Client $client, string $userId, ?string $organizationId, array $scopes, AuthorizationDetails $details, ?int $authTime = null, ?string $acr = null, ?string $resource = null, ?string $dpopJkt = null): IssuedToken
    {
        $this->assertOpen($organizationId);

        if (! $this->inner instanceof AuthorizationDetailsTokenIssuer) {
            // The framework's exchange only reaches this method when the bound issuer is
            // one; a host issuer that is not never carries authorization details.
            return $this->inner->issueForUser($client, $userId, $organizationId, $scopes, $resource, $dpopJkt);
        }

        return $this->inner->issueForExchange($client, $userId, $organizationId, $scopes, $details, $authTime, $acr, $resource, $dpopJkt);
    }

    private function assertOpen(?string $organizationId): void
    {
        if (OrganizationStanding::isClosed($organizationId)) {
            throw InvalidGrant::make('the organization is not active');
        }
    }
}
