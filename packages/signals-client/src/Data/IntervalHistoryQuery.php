<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\IntervalWire;
use DateTimeImmutable;
use InvalidArgumentException;

readonly class IntervalHistoryQuery
{
    public function __construct(public DateTimeImmutable $from, public DateTimeImmutable $through, public ?DateTimeImmutable $asOf = null, public ?string $cursor = null, public int $limit = 100)
    {
        if ($through <= $from || $limit < 1 || $limit > 1000 || ($cursor !== null && ($cursor === '' || strlen($cursor) > 2048))) {
            throw new InvalidArgumentException('Invalid interval history query.');
        }
    }

    /** @return array<string,int|string> */
    public function toArray(): array
    {
        return ['from' => IntervalWire::utc($this->from), 'through' => IntervalWire::utc($this->through), 'limit' => $this->limit, ...($this->asOf === null ? [] : ['as_of' => IntervalWire::utc($this->asOf)]), ...($this->cursor === null ? [] : ['cursor' => $this->cursor])];
    }
}
