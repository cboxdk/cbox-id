<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;

/**
 * One tier-1 area: an icon in the rail, and the pages behind it.
 *
 * `current` is not the same as `active`, and the difference is the whole of why both are
 * here. `active` means this area owns the page being viewed, and paints the rail's filled
 * marker. `current` means the rail LINK IS the page — true only for a single-page area,
 * because when there is a second tier the sub-nav entry carries `aria-current`, and two
 * elements claiming to be the current page is worse than none.
 */
final readonly class NavAreaProps implements Prop
{
    /**
     * @param  list<NavPageProps>  $pages
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $href,
        public bool $active,
        public bool $current,
        public array $pages,
    ) {}

    /**
     * @return array{key: string, label: string, icon: string, href: string, active: bool, current: bool, pages: list<NavPageProps>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'icon' => $this->icon,
            'href' => $this->href,
            'active' => $this->active,
            'current' => $this->current,
            'pages' => $this->pages,
        ];
    }
}
