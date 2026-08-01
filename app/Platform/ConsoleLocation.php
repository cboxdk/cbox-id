<?php

declare(strict_types=1);

namespace App\Platform;

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
 */
final readonly class ConsoleLocation
{
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

        return null;
    }
}
