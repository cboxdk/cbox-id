<?php

declare(strict_types=1);

namespace Cbox\Signals\Data;

/** Explicit filters and opaque cursor; no implicit auto-pagination. */
readonly class SignalFilter
{
    public function __construct(public ?string $after = null, public ?string $severity = null, public ?string $status = null, public ?string $type = null, public ?string $entity = null) {}

    /** @return array<string,string> */
    public function toArray(): array
    {
        return array_filter(['after' => $this->after, 'severity' => $this->severity, 'status' => $this->status, 'type' => $this->type, 'entity' => $this->entity], static fn (?string $value): bool => $value !== null);
    }
}
