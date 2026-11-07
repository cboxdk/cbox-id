<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;

readonly class Erasure
{
    public function __construct(public string $id, public ErasureState $status) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Wire::text($data['id'] ?? null), ErasureState::tryFrom(Wire::text($data['status'] ?? null)) ?? Wire::fail());
    }
}
