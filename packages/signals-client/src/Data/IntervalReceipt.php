<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

readonly class IntervalReceipt
{
    /** @param list<IntervalAcknowledgement> $records */
    public function __construct(public DateTimeImmutable $acceptedAt, public int $created, public int $corrected, public int $duplicates, public array $records) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $records = array_map(static fn (mixed $item): IntervalAcknowledgement => IntervalAcknowledgement::fromArray(Wire::object($item)), Wire::items($data['records'] ?? null));
        $created = Wire::integer($data['created'] ?? null);
        $corrected = Wire::integer($data['corrected'] ?? null);
        $duplicates = Wire::integer($data['duplicates'] ?? null);
        if (count($records) < 1 || count($records) > 1000 || count($records) !== $created + $corrected + $duplicates) {
            Wire::fail();
        }
        $outcomes = array_count_values(array_map(static fn (IntervalAcknowledgement $item): string => $item->outcome, $records));
        if (($outcomes['created'] ?? 0) !== $created || ($outcomes['corrected'] ?? 0) !== $corrected || ($outcomes['duplicate'] ?? 0) !== $duplicates) {
            Wire::fail();
        }

        return new self(Wire::date($data['accepted_at'] ?? null), $created, $corrected, $duplicates, $records);
    }
}
