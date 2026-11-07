<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;

readonly class ReplayResult
{
    /** @param array<string,DetectorResult> $results */
    public function __construct(public int $configurationRevision, public string $detectorId, public array $results, public ?int $mappingVersion, public ?int $observations, public ?int $samples) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['mode'] ?? null) !== 'isolated' || ($data['live_deliveries'] ?? null) !== false || ($data['billable_events'] ?? null) !== 0) {
            Wire::fail();
        }
        $results = [];
        foreach (Wire::object($data['results'] ?? null) as $entity => $result) {
            $results[$entity] = DetectorResult::fromArray(Wire::object($result));
        }

        return new self(Wire::integer($data['configuration_revision'] ?? null, 1), Wire::text($data['detector_id'] ?? null), $results, isset($data['mapping_version']) ? Wire::integer($data['mapping_version'], 1) : null, isset($data['observations']) ? Wire::integer($data['observations']) : null, isset($data['samples']) ? Wire::integer($data['samples']) : null);
    }
}
