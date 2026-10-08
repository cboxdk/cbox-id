<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\Webhooks\Enums\EndpointStatus;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;

/**
 * A webhook endpoint — `Webhook` in the spec. `organization_id` null means the environment
 * owns it and it receives every organization's events. `secret` is present only on the
 * answer that minted it (create, rotate), and is null on an idempotent replay of that
 * answer: only its sealed form persists. `signature_scheme` says which headers its
 * deliveries carry — `cbox` (`X-Cbox-Signature`) or `standard_webhooks` (`webhook-id`,
 * `webhook-timestamp`, `webhook-signature`).
 */
final class WebhookResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(WebhookEndpoint $endpoint, ?string $secret = null): array
    {
        $data = [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'organization_id' => $endpoint->organization_id,
            'event_types' => array_values($endpoint->event_types),
            'active' => $endpoint->status === EndpointStatus::Active,
            'signature_scheme' => $endpoint->signature_scheme->value,
            'consecutive_failures' => $endpoint->consecutive_failures,
            'last_success_at' => Timestamp::of($endpoint->last_success_at),
            'created_at' => Timestamp::of($endpoint->getAttribute('created_at')),
        ];

        if ($secret !== null) {
            $data['secret'] = $secret;
        }

        return $data;
    }
}
