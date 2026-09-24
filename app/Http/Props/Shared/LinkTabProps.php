<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;

/**
 * One tab of a page whose tabs are PAGES — each its own URL, so a tab can be linked,
 * bookmarked and reloaded, and the browser's back button moves between them.
 */
final readonly class LinkTabProps implements Prop
{
    public function __construct(
        public string $key,
        public string $label,
        public string $href,
        public bool $current,
    ) {}

    /**
     * @return array{key: string, label: string, href: string, current: bool}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'href' => $this->href,
            'current' => $this->current,
        ];
    }
}
