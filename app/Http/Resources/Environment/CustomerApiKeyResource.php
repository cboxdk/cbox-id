<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\Organization\Models\CustomerApiKey;

/**
 * A key one of an organization's members created for your app's API — `ApiKey` in the
 * spec. Never the key itself, nor its hash: `prefix` is what a person recognises it by.
 */
final class CustomerApiKeyResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(CustomerApiKey $key): array
    {
        $status = match (true) {
            $key->revoked_at !== null => 'revoked',
            $key->expires_at !== null && ! $key->expires_at->isFuture() => 'expired',
            default => 'active',
        };

        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'organization_id' => $key->organization_id,
            'user_id' => $key->user_id,
            'client_id' => $key->client_id,
            'permissions' => $key->permissions,
            'status' => $status,
            'revoked' => $key->revoked_at !== null,
            'created_at' => Timestamp::of($key->getAttribute('created_at')),
            'expires_at' => Timestamp::of($key->expires_at),
            'last_used_at' => Timestamp::of($key->last_used_at),
            'revoked_at' => Timestamp::of($key->revoked_at),
        ];
    }
}
