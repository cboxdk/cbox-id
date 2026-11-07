<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use DateTimeImmutable;

readonly class Event
{
    /** @param array<string,mixed> $data Application-defined JSON at the transport boundary. */
    public function __construct(public string $id, public string $name, public int $schemaVersion, public DateTimeImmutable $occurredAt, public array $data) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'schema_version' => $this->schemaVersion, 'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'), 'data' => $this->data === [] ? new \stdClass : $this->data];
    }
}
