<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;

/**
 * One choice in a picker: the value the form sends, and the words a person reads.
 */
final readonly class OptionProps implements Prop
{
    public function __construct(
        public string $value,
        public string $label,
    ) {}

    /**
     * @return array{value: string, label: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label];
    }
}
