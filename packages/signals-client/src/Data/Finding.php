<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

use Cbox\Signals\Support\Wire;

readonly class Finding
{
    /**
     * @param  list<string>  $reasons
     * @param  list<Evidence>  $evidence
     */
    public function __construct(public string $type, public Severity $severity, public array $reasons, public array $evidence) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(Wire::text($data['type'] ?? null), Severity::tryFrom(Wire::text($data['severity'] ?? null)) ?? Wire::fail(), Wire::strings($data['reasons'] ?? null), array_map(fn (mixed $item): Evidence => Evidence::fromArray(Wire::object($item)), Wire::items($data['evidence'] ?? null)));
    }
}
