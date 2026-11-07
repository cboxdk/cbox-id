<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;
use DateTimeImmutable;

readonly class IntervalPage
{
    /** @param list<IntervalObservation> $data */
    public function __construct(public array $data, public DateTimeImmutable $asOf, public DateTimeImmutable $retiredThrough, public ?string $nextCursor) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('next_cursor', $data)) {
            Wire::fail();
        }
        $rows = array_map(static fn (mixed $item): IntervalObservation => IntervalObservation::fromArray(Wire::object($item)), Wire::items($data['data'] ?? null));
        $cursor = $data['next_cursor'] === null ? null : Wire::text($data['next_cursor']);
        $asOf = Wire::date($data['as_of'] ?? null);
        if (count($rows) > 1000 || ($cursor !== null && strlen($cursor) > 2048)) {
            Wire::fail();
        }
        foreach ($rows as $row) {
            if ($row->knownAt > $asOf) {
                Wire::fail();
            }
        }

        return new self($rows, $asOf, Wire::date($data['retired_through'] ?? null), $cursor);
    }
}
