<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\IntervalWire;
use Cbox\Signals\Support\Wire;
use InvalidArgumentException;

readonly class IntervalCoverage
{
    /** @param list<string> $reasons */
    public function __construct(public string $status, public ?string $observedThrough, public array $reasons)
    {
        if (! in_array($status, ['complete', 'partial', 'unknown'], true) || count($reasons) > 5 || count(array_unique($reasons)) !== count($reasons)) {
            throw new InvalidArgumentException('Invalid interval coverage.');
        }
        foreach ($reasons as $reason) {
            if (! in_array($reason, ['export_incomplete', 'source_unavailable', 'late_data', 'clock_untrusted', 'missing_population'], true)) {
                throw new InvalidArgumentException('Invalid interval coverage reason.');
            }
        }
        if (($status === 'unknown') !== ($observedThrough === null) || ($status === 'complete') !== ($reasons === [])) {
            throw new InvalidArgumentException('Coverage needs explicit availability and reasons.');
        }
        if ($observedThrough !== null) {
            IntervalWire::timestamp($observedThrough);
        }
    }

    /** @return array{status:string,observed_through:?string,reasons:list<string>} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'observed_through' => $this->observedThrough, 'reasons' => $this->reasons];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! array_key_exists('observed_through', $data)) {
            Wire::fail();
        }

        return new self(Wire::text($data['status'] ?? null), $data['observed_through'] === null ? null : Wire::text($data['observed_through']), Wire::strings($data['reasons'] ?? null));
    }
}
