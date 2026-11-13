<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use Cbox\Id\OAuthServer\Models\AccessToken;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;

/** Machine issuance proof for this application's builtin OAuth token ledger. */
final class MachineTokenAuthority
{
    public static function issued(Introspection $token): bool
    {
        if (! $token->isMachine() || $token->clientId === null) {
            return false;
        }

        $jti = $token->claims['jti'] ?? null;

        return is_string($jti) && $jti !== '' && AccessToken::query()
            ->where('jti', $jti)->where('client_id', $token->clientId)
            ->whereNull('user_id')->whereNull('support_session_id')
            ->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
    }
}
