<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use Cbox\Id\Webhooks\Enums\EndpointStatus;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;

/**
 * One endpoint in the list.
 *
 * `owner` is the organization's NAME, resolved through the acting scope's own list rather
 * than a bare Organization lookup — so naming an endpoint's owner can never enumerate the
 * environment's other tenants. Null means the endpoint belongs to the environment itself
 * and receives every organization's events.
 */
final readonly class WebhookRowProps implements Prop
{
    public function __construct(
        public string $id,
        public string $url,
        public string $href,
        public bool $active,
        public ?string $owner,
        public int $eventCount,
    ) {}

    /**
     * @param  array<string, string>  $organizationNames
     */
    public static function from(WebhookEndpoint $endpoint, string $href, array $organizationNames): self
    {
        $organizationId = $endpoint->organization_id;

        return new self(
            id: $endpoint->id,
            url: $endpoint->url,
            href: $href,
            active: $endpoint->status === EndpointStatus::Active,
            owner: $organizationId !== null
                ? ($organizationNames[$organizationId] ?? $organizationId)
                : null,
            eventCount: count($endpoint->event_types),
        );
    }

    /**
     * @return array{id: string, url: string, href: string, active: bool, owner: string|null, eventCount: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'href' => $this->href,
            'active' => $this->active,
            'owner' => $this->owner,
            'eventCount' => $this->eventCount,
        ];
    }
}
