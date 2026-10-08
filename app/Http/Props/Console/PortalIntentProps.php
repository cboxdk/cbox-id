<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Platform\Enums\PortalIntent;

/**
 * One {@see PortalIntent} as the console's "Admin Portal link" dialog offers it: a checkbox
 * with a sentence under it, disabled when the organization's plan does not include it.
 */
final readonly class PortalIntentProps implements Prop
{
    public function __construct(
        public string $value,
        public string $label,
        public string $description,
        public bool $available,
    ) {}

    /**
     * @return array{value: string, label: string, description: string, available: bool}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'description' => $this->description,
            'available' => $this->available,
        ];
    }
}
