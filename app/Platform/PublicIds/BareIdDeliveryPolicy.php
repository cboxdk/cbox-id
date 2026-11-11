<?php

declare(strict_types=1);

namespace App\Platform\PublicIds;

use Cbox\Id\Webhooks\Contracts\WebhookDeliveryPolicy;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;

/**
 * THE DELIVERY POLICY READS BARE IDS, whatever form the endpoint receives.
 *
 * The dispatcher asks the policy twice: when it fans an event out (the event's own payload)
 * and again before each attempt (the STORED payload, which {@see WebhookIdFormat} rewrote
 * for an endpoint that asked for public ids). A policy looks the payload's ids up — the
 * property policy asks whether `user_id` is still an active subject — and `usr_01j…` is no
 * subject, so without this every such delivery to a `prefixed` endpoint was exhausted
 * without a word. The policy is handed the payload as the event carried it.
 */
final readonly class BareIdDeliveryPolicy implements WebhookDeliveryPolicy
{
    public function __construct(private WebhookDeliveryPolicy $inner) {}

    public function allows(WebhookEndpoint $endpoint, string $eventType, array $payload): bool
    {
        /** @var array<string, mixed> $bare */
        $bare = WebhookIdFormat::ofEndpoint($endpoint) === WebhookIdFormat::PREFIXED ? PublicIdTree::strip($payload) : $payload;

        return $this->inner->allows($endpoint, $eventType, $bare);
    }
}
