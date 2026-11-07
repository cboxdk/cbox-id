<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

readonly class MetricBatch
{
    /** @param list<MetricSample> $samples */
    public function __construct(public string $sourceId, public array $samples) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['source_id' => $this->sourceId, 'samples' => array_map(fn (MetricSample $sample): array => $sample->toArray(), $this->samples)];
    }
}
