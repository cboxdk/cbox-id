<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use Cbox\Id\Webhooks\Enums\DeliveryStatus;
use Cbox\Id\Webhooks\Models\WebhookDelivery;

/**
 * One attempt to hand an event to an endpoint.
 *
 * The two timestamps travel as ISO strings and are rendered relative in the browser,
 * because "delivered 4 minutes ago" computed on the server is wrong the moment the page
 * sits open — and this is a page people leave open while they wait for a retry.
 */
final readonly class WebhookDeliveryProps implements Prop
{
    public function __construct(
        public string $id,
        public string $eventType,
        public int $attempt,
        public ?int $responseCode,
        public string $status,
        public bool $delivered,
        public ?string $deliveredAt,
        public ?string $nextRetryAt,
    ) {}

    public static function from(WebhookDelivery $delivery): self
    {
        return new self(
            id: (string) $delivery->id,
            eventType: $delivery->event_type,
            attempt: $delivery->attempt,
            responseCode: $delivery->response_code,
            status: $delivery->status->value,
            delivered: $delivery->status === DeliveryStatus::Delivered,
            deliveredAt: $delivery->delivered_at?->toIso8601String(),
            nextRetryAt: $delivery->next_retry_at?->toIso8601String(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'eventType' => $this->eventType,
            'attempt' => $this->attempt,
            'responseCode' => $this->responseCode,
            'status' => $this->status,
            'delivered' => $this->delivered,
            'deliveredAt' => $this->deliveredAt,
            'nextRetryAt' => $this->nextRetryAt,
        ];
    }
}
