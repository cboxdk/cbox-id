<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;

/**
 * ONE MODULE'S CARD ON THE DASHBOARD, as data rather than as markup.
 *
 * A module used to contribute a card by returning a rendered HTML STRING through the
 * console-kit slot registry, which the dashboard echoed into its grid. That worked while
 * the dashboard was a blade template and stops working the moment it is not — and pasting
 * module HTML into a React page with `dangerouslySetInnerHTML` would keep it working in the
 * worst possible way: five modules each free to emit whatever markup they like into the
 * console's own layout, with no shared shape, no theme discipline and no way for the page to
 * lay them out consistently.
 *
 * So a card states WHAT IT IS and the console decides how it looks. Every one of the five
 * that existed fitted this shape without losing anything: a labelled number, sometimes a
 * sentence under it, and a link to the page it summarises.
 */
final readonly class DashboardCardProps implements Prop
{
    /**
     * @param  string  $icon  an icon the design system HAS — the card cannot ship its own
     *                        SVG, which is how four of the five came to carry hand-drawn
     *                        paths at four different sizes
     * @param  'info'|'success'|'warning'|'neutral'  $tone
     * @param  string|null  $swatch  a colour to tint the icon tile with, for a card whose
     *                               subject IS a colour. CSS, not markup.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $value,
        public ?string $caption,
        public string $icon,
        public string $tone,
        public ?string $linkLabel,
        public ?string $linkHref,
        public ?string $swatch = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'caption' => $this->caption,
            'icon' => $this->icon,
            'tone' => $this->tone,
            'linkLabel' => $this->linkLabel,
            'linkHref' => $this->linkHref,
            'swatch' => $this->swatch,
        ];
    }
}
