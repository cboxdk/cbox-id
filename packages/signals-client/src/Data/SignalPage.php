<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;

readonly class SignalPage
{
    /** @param list<SignalRecord> $data */
    public function __construct(public array $data, public ?string $nextCursor) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('next_cursor', $data)) {
            Wire::fail();
        }

        return new self(array_map(static fn (mixed $item): SignalRecord => SignalRecord::fromArray(Wire::object($item)), Wire::items($data['data'] ?? null)), $data['next_cursor'] === null ? null : Wire::text($data['next_cursor']));
    }
}
