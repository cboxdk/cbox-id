<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use DateTimeImmutable;

readonly class Receipt
{
    public function __construct(public string $operationId, public int $acceptedCount, public DateTimeImmutable $acceptedAt) {}
}
