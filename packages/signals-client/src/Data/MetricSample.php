<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use DateTimeImmutable;

readonly class MetricSample
{
    /** @param array<string,string> $dimensions */
    public function __construct(public string $id, public string $name, public float $value, public string $unit, public MetricKind $kind, public DateTimeImmutable $observedAt, public Entity $entity, public array $dimensions = [], public ?DateTimeImmutable $intervalStart = null, public ?DateTimeImmutable $intervalEnd = null) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $data = ['id' => $this->id, 'name' => $this->name, 'value' => $this->value, 'unit' => $this->unit, 'kind' => $this->kind->value, 'observed_at' => $this->observedAt->format('Y-m-d\TH:i:s.uP'), 'entity' => $this->entity->toArray(), 'dimensions' => (object) $this->dimensions];
        if ($this->intervalStart !== null) {
            $data['interval_start'] = $this->intervalStart->format('Y-m-d\TH:i:s.uP');
        }
        if ($this->intervalEnd !== null) {
            $data['interval_end'] = $this->intervalEnd->format('Y-m-d\TH:i:s.uP');
        }

        return $data;
    }
}
