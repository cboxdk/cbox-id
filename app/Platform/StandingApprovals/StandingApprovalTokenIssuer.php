<?php

declare(strict_types=1);

namespace App\Platform\StandingApprovals;

use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\AudienceResolver;
use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Support\AccessTokenLifetime;
use Cbox\Id\OAuthServer\ValueObjects\AuthorizationDetails;
use Cbox\Id\OAuthServer\ValueObjects\IssuedToken;
use Cbox\Id\Organization\Contracts\Organizations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mints the approval token a standing approval stands for: the token a person's approval
 * is, with the machine client as its subject.
 *
 * THE SAME SHAPE AND THE SAME VERIFICATION. Signed by the environment's key as an `at+jwt`,
 * `iss`, `sub` (the machine client), `client_id` (the app that exchanged for it), `org`,
 * `aud` (the resource asked for, resolved exactly as for any token), `iat`, `exp` and the
 * RFC 9396 `authorization_details` naming the plan — and recorded by `jti` like every access
 * token, so it is introspectable and revocable. No roles, no login facts: there is no person
 * behind it. No TokenMinting hook runs, so nothing but this class decides its claims.
 *
 * Short-lived: the deployment it approves is checked by its engine when it starts, and the
 * framework's own default lifetime (or the app's shorter one) is the most it gets.
 */
final readonly class StandingApprovalTokenIssuer
{
    /** The longest a standing approval's token lives, in seconds. */
    public const int MAX_TTL_SECONDS = 900;

    public function __construct(
        private TokenSigner $signer,
        private IssuerResolver $issuers,
        private AudienceResolver $audiences,
        private Organizations $organizations,
    ) {}

    public function issue(Client $app, string $machineClientId, string $organizationId, AuthorizationDetails $details, ?string $resource): IssuedToken
    {
        $audience = $this->audiences->resolve($app, [], $resource);
        $ttl = min(self::MAX_TTL_SECONDS, AccessTokenLifetime::for($app, self::MAX_TTL_SECONDS));
        $jti = (string) Str::ulid();
        $issuedAt = time();

        $claims = [
            'iss' => $this->issuers->issuer(),
            'sub' => $machineClientId,
            'client_id' => $app->client_id,
            'jti' => $jti,
            'scope' => implode(' ', $audience->scopes),
            'org' => $organizationId,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttl,
            'aud' => $audience->claim($this->issuers->issuer()),
            'authorization_details' => $details->claim(),
        ];

        $name = $this->organizations->find($organizationId)?->name;

        if (is_string($name) && $name !== '') {
            $claims['org_name'] = $name;
        }

        return DB::transaction(function () use ($claims, $app, $organizationId, $audience, $jti, $issuedAt, $ttl): IssuedToken {
            $token = $this->signer->sign($claims, type: 'at+jwt');

            AccessToken::query()->create([
                'jti' => $jti,
                'client_id' => $app->client_id,
                'user_id' => null,
                'organization_id' => $organizationId,
                'scopes' => $audience->scopes,
                'audience' => $audience->resource,
                'expires_at' => now()->setTimestamp($issuedAt + $ttl),
            ]);

            return new IssuedToken($token, $jti, $ttl, 'Bearer', $audience->scopes, $audience->resource);
        });
    }
}
