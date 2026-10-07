<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\Platform\Models\EnvironmentApiKey;

/**
 * A management key as the API shows it: everything about it but its value, which exists
 * once, in the answer that minted it (`token`).
 */
final class ManagementKeyResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(EnvironmentApiKey $key, ?string $token = null): array
    {
        $data = [
            'id' => $key->id,
            'name' => $key->name,
            'description' => $key->description,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes,
            'active' => $key->isActive(),
            'parent_key_id' => $key->parent_key_id,
            'rotated_from_id' => $key->rotated_from_id,
            'created_by' => $key->created_by_type === null ? null : ['type' => $key->created_by_type, 'id' => $key->created_by_id],
            'expires_at' => Timestamp::of($key->expires_at),
            'last_used_at' => Timestamp::of($key->last_used_at),
            'revoked_at' => Timestamp::of($key->revoked_at),
            'created_at' => Timestamp::of($key->getAttribute('created_at')),
        ];

        if ($token !== null) {
            $data['token'] = $token;
        }

        return $data;
    }
}
