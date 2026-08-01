<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Console\Kit\Facades\Console;

/**
 * Answers "where am I?" from the nav registry rather than from a string typed into
 * each page.
 *
 * The eyebrow above a page title exists to tell you which area of the console you are
 * standing in. Hand-written, it drifts: the console shipped pages whose eyebrow said
 * "Authentication", "Security" or "You" while the sidebar that got you there said
 * "Sign-in", "Developers" and "My account" — the one label whose whole job is
 * orientation, disagreeing with the navigation. Deriving it from the same registry the
 * sidebar renders from makes that class of bug unrepresentable, and a plugin's page
 * gets a correct eyebrow for free.
 *
 * Two sources, because the console has two kinds of navigation. The organization plane
 * is assembled at runtime from the plugin registry, so it can only be read through
 * {@see Console::nav()}. The other three planes are fixed and declared in
 * {@see ConsoleNavigation}. Until both were consulted this answered null on all 41
 * workspace, environment and operator pages — every one of them rendered with no
 * eyebrow at all, which is why the feature looked like it only half worked.
 */
final readonly class ConsoleLocation
{
    public function __construct(private ConsoleNavigation $navigation) {}

    /** The label of the area owning the given route (defaults to the current one). */
    public function areaLabel(?string $route = null): ?string
    {
        $route = $route ?? request()->route()?->getName();

        if (! is_string($route) || $route === '') {
            return null;
        }

        foreach (Console::nav()->areas() as $area) {
            foreach ($area->pages() as $page) {
                // Routes are named after the page ('connections'), and sub-pages hang
                // off that name ('connections.edit') — the same prefix match the
                // sidebar uses to decide which entry is active.
                if ($route === $page->route || str_starts_with($route, $page->route.'.')) {
                    return $area->label;
                }
            }
        }

        foreach ($this->navigation->all() as $nav) {
            $area = $nav->areaFor($route);

            if ($area !== null) {
                return $area->label;
            }
        }

        return null;
    }
}
