<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;

/**
 * One row in a switcher — an organization the signed-in person belongs to, or an
 * environment an operator can point the console at.
 *
 * `caption` is the second line, and it is what makes the control usable rather than
 * decorative: "Production" is a name half the customers on an install will have, so a
 * switcher that shows only the name tells an operator nothing about whose estate their
 * next click lands in. For an organization it is the membership role; for an environment
 * it is the owner, reached through the project.
 */
final readonly class SwitchOptionProps implements Prop
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $caption,
        public bool $current,
        /** Where this row leads when it is more than a selection — an environment's own console. */
        public ?string $openHref = null,
    ) {}

    /**
     * @return array{id: string, label: string, caption: string|null, current: bool, openHref: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'caption' => $this->caption,
            'current' => $this->current,
            'openHref' => $this->openHref,
        ];
    }
}
