<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;

readonly class Evidence
{
    /** @param list<string> $occurrences */
    public function __construct(public string $measure, public float $value, public array $occurrences, public ?float $baseline = null) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Wire::text($data['measure'] ?? null), Wire::number($data['value'] ?? null), Wire::strings($data['occurrences'] ?? null), array_key_exists('baseline', $data) ? Wire::number($data['baseline']) : null);
    }
}
