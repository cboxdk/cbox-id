<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\ExternalActions\Enums\ActionEndpointStatus;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;

/**
 * An inline hook — `InlineHook` in the spec: an endpoint called synchronously at its
 * `hook_point`, whose answer can add claims or refuse the operation. `organization_id`
 * null means it runs for every organization. `secret` only on the answer that minted it.
 */
final class InlineHookResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(ExternalActionEndpoint $endpoint, ?string $secret = null): array
    {
        $data = [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'hook_point' => $endpoint->hook_point->value,
            'organization_id' => $endpoint->organization_id,
            'active' => $endpoint->status === ActionEndpointStatus::Active,
            'created_at' => Timestamp::of($endpoint->getAttribute('created_at')),
        ];

        if ($secret !== null) {
            $data['secret'] = $secret;
        }

        return $data;
    }
}
