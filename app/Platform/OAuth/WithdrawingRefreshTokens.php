<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Exceptions\InvalidGrant;
use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\RefreshToken;
use Cbox\Id\OAuthServer\RefreshTokenGrantRevoker;
use Cbox\Id\OAuthServer\ValueObjects\RefreshGrant;
use Illuminate\Database\Eloquent\Builder;

/**
 * Refresh tokens that end what they should end.
 *
 * TWO GAPS IN THE FRAMEWORK'S STORE, closed around it:
 *
 * 1. A withdrawn grant left its access tokens valid. Withdrawing an app from the account
 *    page, withdrawing a person's access (removal, an archived organization, an
 *    administrator's "sign out everywhere") and revoking a refresh token at `/oauth/revoke`
 *    revoked refresh-token rows only. The app's access token stayed active, and a client
 *    with the token-exchange grant exchanged it for a fresh one before each expiry,
 *    indefinitely. The access tokens of the same (person, app[, organization]) are revoked
 *    with the grant now. Deactivation already did this ({@see RefreshTokenGrantRevoker}).
 *
 *    `revokeForUser()` is deliberately left alone: it is the role-change verb, and a role
 *    change signs nobody out (a refresh re-reads the roles).
 *
 * 2. A refresh for a closed organization was rotated before the issuer refused it, so the
 *    client lost the refresh token it would need once the organization is reactivated.
 *    Asked here, before the inner store consumes anything.
 */
final readonly class WithdrawingRefreshTokens implements RefreshTokens
{
    public function __construct(private RefreshTokens $inner) {}

    public function issue(Client $client, ?string $userId, ?string $organizationId, array $scopes, ?string $audience = null, ?string $dpopJkt = null, ?int $authTime = null, array $amr = [], ?string $sessionId = null): string
    {
        return $this->inner->issue($client, $userId, $organizationId, $scopes, $audience, $dpopJkt, $authTime, $amr, $sessionId);
    }

    public function rotate(string $clientId, string $rawToken, ?string $presentedJkt = null, ?string $resource = null): RefreshGrant
    {
        $organizationId = RefreshToken::query()->where('token_hash', hash('sha256', $rawToken))->value('organization_id');

        if (is_string($organizationId) && OrganizationStanding::isClosed($organizationId)) {
            // The framework's own wording for a token it will not rotate: a closed
            // organization is not announced to whoever holds the token.
            throw InvalidGrant::make('refresh token invalid, expired or revoked');
        }

        return $this->inner->rotate($clientId, $rawToken, $presentedJkt, $resource);
    }

    public function revoke(string $rawToken, ?string $clientId = null): void
    {
        $token = RefreshToken::query()->where('token_hash', hash('sha256', $rawToken))->first();

        $this->inner->revoke($rawToken, $clientId);

        // RFC 7009 §2.1: revoking a refresh token SHOULD also invalidate the access tokens
        // issued from the same grant. Only for the client that owns the token, as the
        // inner revocation itself is.
        if ($token !== null && $token->user_id !== null && ($clientId === null || hash_equals($token->client_id, $clientId))) {
            $this->revokeAccessTokens($token->user_id, $token->client_id, $token->organization_id, scopedToOrganization: true);
        }
    }

    public function revokeForUser(string $userId, ?string $organizationId = null): int
    {
        return $this->inner->revokeForUser($userId, $organizationId);
    }

    public function withdrawAccess(string $userId, ?string $organizationId = null): int
    {
        $withdrawn = $this->inner->withdrawAccess($userId, $organizationId);

        $this->revokeAccessTokens($userId, null, $organizationId, scopedToOrganization: $organizationId !== null);

        return $withdrawn;
    }

    public function connectedApplications(string $userId): array
    {
        return $this->inner->connectedApplications($userId);
    }

    public function revokeForUserAndClient(string $userId, string $clientId): int
    {
        $withdrawn = $this->inner->revokeForUserAndClient($userId, $clientId);

        $this->revokeAccessTokens($userId, $clientId, null, scopedToOrganization: false);

        return $withdrawn;
    }

    private function revokeAccessTokens(string $userId, ?string $clientId, ?string $organizationId, bool $scopedToOrganization): void
    {
        AccessToken::query()
            ->where('user_id', $userId)
            ->when($clientId !== null, static fn (Builder $query): Builder => $query->where('client_id', $clientId))
            ->when($scopedToOrganization, static fn (Builder $query): Builder => $organizationId === null
                ? $query->whereNull('organization_id')
                : $query->where('organization_id', $organizationId))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
