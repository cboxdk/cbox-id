<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use DateTimeImmutable;

readonly class ReplayRequest
{
    public function __construct(public DateTimeImmutable $from, public DateTimeImmutable $to, public int $configurationRevision, public int $mappingVersion, public string $detectorId) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['from' => $this->from->format('Y-m-d\TH:i:s.uP'), 'to' => $this->to->format('Y-m-d\TH:i:s.uP'), 'configuration_revision' => $this->configurationRevision, 'mapping_version' => $this->mappingVersion, 'detector_id' => $this->detectorId];
    }
}
