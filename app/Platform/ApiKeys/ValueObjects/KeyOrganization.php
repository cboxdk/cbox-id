<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys\ValueObjects;

/** An organization a person can hold keys in: one they are an active member of. */
final readonly class KeyOrganization
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
