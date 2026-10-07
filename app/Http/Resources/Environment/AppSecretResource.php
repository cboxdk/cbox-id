<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\OAuthServer\ValueObjects\ClientSecretSummary;

/**
 * One live secret of an app — `AppSecret` in the spec: its id (what revoking names), the
 * last characters it ends in, and its dates. Never the secret, never its hash.
 *
 * `expires_at` is set on a secret a rotation replaced: it keeps working until then, the
 * overlap in which deployments move to the new one.
 *
 * `client_secret` is present ONLY on the response that minted it (a rotation) — the server
 * keeps a hash, so this is the one chance to read it.
 */
final class AppSecretResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(ClientSecretSummary $secret, ?string $plaintext = null): array
    {
        $out = [
            'id' => $secret->id,
            'hint' => $secret->hint,
            'created_at' => Timestamp::of($secret->createdAt),
            'expires_at' => Timestamp::of($secret->expiresAt),
            'last_used_at' => Timestamp::of($secret->lastUsedAt),
        ];

        if ($plaintext !== null && $plaintext !== '') {
            $out['client_secret'] = $plaintext;
        }

        return $out;
    }
}
