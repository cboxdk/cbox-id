<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys\ValueObjects;

/**
 * A permission a key may carry: its `feature:action` name, and what the app's manifest
 * says it means. The description is what a person reads; the name is what the app checks.
 */
final readonly class KeyPermission
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}
