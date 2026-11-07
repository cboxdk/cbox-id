<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use DateTimeImmutable;

readonly class Signal
{
    /** @param list<string> $reasons */
    public function __construct(public string $type, public string $severity, public array $reasons, public DateTimeImmutable $expiresAt, public ?string $id = null, public ?int $revision = null) {}
}
