<?php

declare(strict_types=1);

namespace App\Platform\AppCatalog;

/**
 * The hue an app's tile is drawn in. Each is a palette family with a light and a dark
 * step, so the switcher paints it in either theme; the value is the token name, never a
 * colour, so a theme can retune it without touching the catalog.
 */
enum CatalogHue: string
{
    case Red = 'red';
    case Orange = 'orange';
    case Amber = 'amber';
    case Yellow = 'yellow';
    case Lime = 'lime';
    case Green = 'green';
    case Emerald = 'emerald';
    case Teal = 'teal';
    case Cyan = 'cyan';
    case Sky = 'sky';
    case Blue = 'blue';
    case Indigo = 'indigo';
    case Violet = 'violet';
    case Purple = 'purple';
    case Fuchsia = 'fuchsia';
    case Pink = 'pink';
    case Rose = 'rose';
    case Slate = 'slate';
    case Stone = 'stone';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $hue): string => $hue->value, self::cases());
    }
}
