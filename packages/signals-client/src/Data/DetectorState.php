<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

readonly class DetectorState
{
    /** @param array<string,int> $counts */
    public function __construct(public string $id, public string $mode, public string $status, public int $entities, public array $counts, public ?DateTimeImmutable $dataAsOf) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('data_as_of', $data)) {
            Wire::fail();
        }
        $counts = [];
        foreach (Wire::object($data['counts'] ?? null) as $key => $value) {
            $counts[$key] = Wire::integer($value);
        }

        return new self(Wire::text($data['id'] ?? null), Wire::text($data['mode'] ?? null), Wire::text($data['status'] ?? null), Wire::integer($data['entities'] ?? null), $counts, $data['data_as_of'] === null ? null : Wire::date($data['data_as_of']));
    }
}
