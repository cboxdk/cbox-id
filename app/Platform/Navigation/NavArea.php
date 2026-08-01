<?php

declare(strict_types=1);

namespace App\Platform\Navigation;

/**
 * A first-tier area: one rail icon, one label, and the pages it holds.
 */
readonly class NavArea
{
    /** @var list<NavPage> */
    public array $pages;

    public function __construct(
        public string $label,
        public string $icon,
        NavPage ...$pages,
    ) {
        $this->pages = array_values($pages);
    }

    public function isCurrent(): bool
    {
        foreach ($this->pages as $page) {
            if ($page->isCurrent()) {
                return true;
            }
        }

        return false;
    }

    public function owns(string $route): bool
    {
        foreach ($this->pages as $page) {
            if ($page->owns($route)) {
                return true;
            }
        }

        return false;
    }

    /** The rail links to the area's first page. */
    public function href(): string
    {
        return $this->pages[0]->href();
    }

    /**
     * An area holding one page has nothing to show in the second tier, so the rail
     * icon itself becomes the current item rather than opening an empty sub-nav.
     */
    public function isLeaf(): bool
    {
        return count($this->pages) === 1;
    }
}
