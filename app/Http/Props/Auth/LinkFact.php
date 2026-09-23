<?php

declare(strict_types=1);

namespace App\Http\Props\Auth;

use App\Http\Props\Prop;

/** One labelled fact on a {@see LinkConfirmationProps} page — "Organization: Acme". */
final readonly class LinkFact implements Prop
{
    public function __construct(
        public string $label,
        public string $value,
    ) {}

    /**
     * @return array{label: string, value: string}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'value' => $this->value];
    }
}
