<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\OAuthServer\Models\Api;
use Cbox\Id\OAuthServer\Models\ApiScope;

/**
 * A registered API (resource server) — `Api` in the spec. `identifier` is the absolute URI
 * that becomes the token's `aud`; `client_id` is the app whose roles and permissions the
 * API enforces; `organization_id` null means the environment owns it.
 */
final class ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Api $api): array
    {
        return [
            'id' => $api->id,
            'identifier' => $api->identifier,
            'name' => $api->name,
            'organization_id' => $api->organization_id,
            'client_id' => $api->client_id,
            'scopes' => $api->scopes()->orderBy('key')->get()->map(fn (ApiScope $scope): array => [
                'key' => $scope->key,
                'description' => $scope->description,
                'tenant_requestable' => $scope->tenant_requestable,
            ])->values()->all(),
            'created_at' => Timestamp::of($api->created_at),
        ];
    }
}
