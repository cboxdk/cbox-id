<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\OAuthServer\Models\SupportSession;

/**
 * A support session that has begun — `SupportSession` in the spec.
 *
 * `code` is the first single-use authorization code, present only when the request asked
 * for one (a `redirect_uri` and a PKCE `code_challenge`). The app redeems it at
 * `/oauth/token` with the matching verifier like any other code; the tokens it gets carry
 * `act: {sub: actor_id}` and no refresh token. It is shown once — only its hash is kept.
 */
final class SupportSessionResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(SupportSession $session, ?string $code, ?string $redirectUri): array
    {
        return [
            'id' => $session->id,
            'user_id' => $session->target_user_id,
            'organization_id' => $session->organization_id,
            'client_id' => $session->client_id,
            'actor_id' => $session->actor_id,
            'act' => ['sub' => $session->actor_id],
            'reason' => $session->reason,
            'scopes' => array_values($session->scopes),
            'expires_at' => Timestamp::of($session->expires_at),
            'code' => $code,
            'redirect_uri' => $code === null ? null : $redirectUri,
        ];
    }
}
