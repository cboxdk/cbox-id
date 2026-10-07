<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\Kernel\Events\Models\Event;

/**
 * A domain event from the outbox — `DomainEvent` in the spec: the same fact a webhook
 * delivers, read instead of pushed. `id` is the cursor.
 */
final class DomainEventResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Event $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type,
            'organization_id' => $event->organization_id,
            'occurred_at' => Timestamp::of($event->occurred_at),
            // An object even when empty: `[]` would encode as a JSON array.
            'payload' => $event->payload === [] ? (object) [] : $event->payload,
        ];
    }
}
