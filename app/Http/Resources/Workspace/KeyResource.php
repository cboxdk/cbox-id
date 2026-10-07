<?php

declare(strict_types=1);

namespace App\Http\Resources\Workspace;

use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\OrganizationApiKey;

/**
 * The keys the workspace plane mints — a workspace key (`WorkspaceKey`) and an
 * environment's management key (`EnvironmentKey`) — never with the hash, and with the
 * value (`token`) only in the answer that minted it. An action that returns one names
 * `token` in its `redact`, so an idempotent replay does not carry it either.
 */
final class KeyResource
{
    /**
     * @return array<string, mixed>
     */
    public static function workspace(OrganizationApiKey $key, ?string $token = null): array
    {
        $data = [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'role' => $key->role->value,
            // Null: bounded by the role alone, as every key minted before scopes existed.
            'scopes' => $key->scopes,
            'parent_key_id' => $key->parent_key_id,
            'created_by_type' => $key->created_by_type,
            'expires_at' => $key->expires_at?->toIso8601String(),
            'revoked_at' => $key->revoked_at?->toIso8601String(),
            'last_used_at' => $key->last_used_at?->toIso8601String(),
        ];

        if ($token !== null) {
            $data['token'] = $token;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function environment(EnvironmentApiKey $key, ?string $token = null): array
    {
        $data = [
            'id' => $key->id,
            'environment_id' => $key->environment_id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes,
            'expires_at' => $key->expires_at?->toIso8601String(),
        ];

        if ($token !== null) {
            $data['token'] = $token;
        }

        return $data;
    }
}
