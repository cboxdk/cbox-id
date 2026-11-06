<?php

declare(strict_types=1);

namespace App\SignalsData;

use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Exceptions\InvalidGrant;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Models\RefreshToken;
use Cbox\Id\OAuthServer\ValueObjects\RefreshGrant;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Observe the issuer's committed transition; never infer reuse from IP or a failed request. */
final readonly class SourceRefreshTokens implements RefreshTokens
{
    /** @param Closure(): Request $request */
    public function __construct(private RefreshTokens $inner, private SourceLedger $ledger, private IdentityCapture $capture, private Closure $request) {}

    public function issue(Client $client, ?string $userId, ?string $organizationId, array $scopes, ?string $audience = null, ?string $dpopJkt = null, ?int $authTime = null, array $amr = [], ?string $sessionId = null): string
    {
        if ($this->ledger->environment() === null) {
            return $this->inner->issue($client, $userId, $organizationId, $scopes, $audience, $dpopJkt, $authTime, $amr, $sessionId);
        }

        return DB::transaction(function () use ($client, $userId, $organizationId, $scopes, $audience, $dpopJkt, $authTime, $amr, $sessionId): string {
            $raw = $this->inner->issue($client, $userId, $organizationId, $scopes, $audience, $dpopJkt, $authTime, $amr, $sessionId);
            $this->capture->token(RefreshToken::query()->where('token_hash', hash('sha256', $raw))->firstOrFail(), 'issued', ($this->request)());

            return $raw;
        });
    }

    public function rotate(string $clientId, string $rawToken, ?string $presentedJkt = null, ?string $resource = null): RefreshGrant
    {
        if ($this->ledger->environment() === null) {
            return $this->inner->rotate($clientId, $rawToken, $presentedJkt, $resource);
        }
        // The issuer intentionally commits a family revocation before throwing
        // InvalidGrant. Returning that refusal across this transaction boundary
        // preserves it; throwing inside would undo the security action.
        $result = DB::transaction(function () use ($clientId, $rawToken, $presentedJkt, $resource): RefreshGrant|InvalidGrant {
            $old = RefreshToken::query()->where('token_hash', hash('sha256', $rawToken))->lockForUpdate()->first();
            try {
                $grant = $this->inner->rotate($clientId, $rawToken, $presentedJkt, $resource);
            } catch (InvalidGrant $refusal) {
                if ($old !== null && $old->revoked_at === null
                    && $refusal->getMessage() === 'invalid_grant: refresh token reuse detected'
                    && $old->fresh()?->revoked_at !== null) {
                    $this->capture->token($old, 'reuse_confirmed', ($this->request)());
                    foreach (RefreshToken::query()->where('family_id', $old->family_id)->whereNotNull('revoked_at')->lazyById(100) as $revoked) {
                        $this->capture->token($revoked, 'revoked', ($this->request)());
                    }
                }

                return $refusal;
            }
            if ($old !== null && $old->consumed_at === null) {
                $this->capture->token($old, 'rotated', ($this->request)());
                $successor = RefreshToken::query()->where('token_hash', hash('sha256', $grant->refreshToken))->firstOrFail();
                $this->capture->token($successor, 'issued', ($this->request)());
            }

            return $grant;
        });
        if ($result instanceof InvalidGrant) {
            throw $result;
        }

        return $result;
    }

    public function revoke(string $rawToken, ?string $clientId = null): void
    {
        if ($this->ledger->environment() === null) {
            $this->inner->revoke($rawToken, $clientId);

            return;
        }
        DB::transaction(function () use ($rawToken, $clientId): void {
            $token = RefreshToken::query()->where('token_hash', hash('sha256', $rawToken))->lockForUpdate()->first();
            if ($token !== null && ($clientId === null || hash_equals($token->client_id, $clientId))) {
                $this->recordRevocations(RefreshToken::query()->where('family_id', $token->family_id)->whereNull('revoked_at'));
            }
            $this->inner->revoke($rawToken, $clientId);
        });
    }

    public function revokeForUser(string $userId, ?string $organizationId = null): int
    {
        return $this->revokeMatching($userId, $organizationId, null, fn (): int => $this->inner->revokeForUser($userId, $organizationId));
    }

    public function withdrawAccess(string $userId, ?string $organizationId = null): int
    {
        return $this->revokeMatching($userId, $organizationId, null, fn (): int => $this->inner->withdrawAccess($userId, $organizationId));
    }

    public function connectedApplications(string $userId): array
    {
        return $this->inner->connectedApplications($userId);
    }

    public function revokeForUserAndClient(string $userId, string $clientId): int
    {
        return $this->revokeMatching($userId, null, $clientId, fn (): int => $this->inner->revokeForUserAndClient($userId, $clientId));
    }

    /** @param Closure(): int $revoke */
    private function revokeMatching(string $user, ?string $organization, ?string $client, Closure $revoke): int
    {
        if ($this->ledger->environment() === null) {
            return $revoke();
        }

        return DB::transaction(function () use ($user, $organization, $client, $revoke): int {
            $query = RefreshToken::query()->where('user_id', $user)->whereNull('revoked_at');
            if ($organization !== null) {
                $query->where('organization_id', $organization);
            }
            if ($client !== null) {
                $query->where('client_id', $client);
            }
            $this->recordRevocations($query);

            return $revoke();
        });
    }

    /** @param Builder<RefreshToken> $query */
    private function recordRevocations(Builder $query): void
    {
        foreach ($query->lockForUpdate()->lazyById(100) as $token) {
            $this->capture->token($token, 'revoked', ($this->request)());
        }
    }
}
