<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

readonly class DetectorResult
{
    public function __construct(public DataQuality $status, public ?DateTimeImmutable $dataAsOf, public ?Finding $finding) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('data_as_of', $data)) {
            Wire::fail();
        }

        return new self(DataQuality::tryFrom(Wire::text($data['status'] ?? null)) ?? Wire::fail(), $data['data_as_of'] === null ? null : Wire::date($data['data_as_of']), isset($data['finding']) ? Finding::fromArray(Wire::object($data['finding'])) : null);
    }
}
