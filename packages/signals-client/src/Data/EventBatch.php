<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

readonly class EventBatch
{
    /** @param list<Event> $events */
    public function __construct(public string $sourceId, public array $events) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['source_id' => $this->sourceId, 'events' => array_map(fn (Event $event): array => $event->toArray(), $this->events)];
    }
}
