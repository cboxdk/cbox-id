<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Health\ConsoleParityHealthCheck;
use App\Platform\Navigation\ConsoleNavigation;
use Cbox\Console\Kit\Facades\Console;
use LogicException;

/**
 * Where a module declares a console page — once, for both planes.
 *
 * The six modules under modules/ each called `Console::nav()->area(...)->page(...)` with
 * a route name. That registry is the ORGANIZATION plane's rail and nothing else, so
 * every one of them shipped on the organization plane only: an environment administrator
 * — the person who owns the environment — had no analytics, no compliance exports, no
 * connectors, no trusted-device inventory, no risk events and no branding.
 *
 * Six modules making the same mistake is not six mistakes. The API took a route name and
 * put it in one rail, and there was no expression for "both", so "one" was what you got
 * by writing nothing. Here the default is both planes, and one plane is a value you have
 * to pass — {@see ConsolePage::$only} — which puts the decision in the diff.
 *
 * Two consumers read this registry:
 *  - {@see ConsoleNavigation::environment()} merges the environment-plane pages into the
 *    environment rail, which is a written list rather than a registry.
 *  - {@see ConsoleParityHealthCheck} measures that each declared plane actually has a
 *    route, so a module that declares both and routes one is reported rather than
 *    discovered by a customer.
 *
 * The organization plane keeps rendering from the console-kit registry, so this writes
 * there too rather than replacing it: a third-party plugin that never hears of this
 * class still appears in the rail exactly as before.
 */
final class ConsolePages
{
    /** @var list<ConsolePage> */
    private array $pages = [];

    /**
     * Declare a console page. It serves BOTH planes unless `$only` says otherwise.
     *
     * @param  string  $route  the route name on the ORGANIZATION plane; the environment
     *                         plane's name is that with an `environment.` prefix, the
     *                         same rule {@see ConsoleScope::routeName()} applies
     * @param  string  $feature  the console-kit feature gating the page, so the rail
     *                           hides it on a deployment where the module is off
     * @param  ConsolePlane|null  $only  a plane, when the page genuinely belongs to one
     *
     * @throws LogicException when a page is declared for both planes in an area that
     *                        exists on only one — an unnoticed contradiction otherwise,
     *                        and one that would drop the page from a rail in silence
     */
    public function add(
        ConsoleArea $area,
        string $route,
        string $label,
        string $feature,
        int $order = 100,
        ?ConsolePlane $only = null,
    ): void {
        if ($only !== ConsolePlane::Organization && $area->environmentLabel() === null) {
            throw new LogicException(sprintf(
                'Console page [%s] is declared for the environment plane, but the [%s] area does not exist there. '
                .'Pass only: ConsolePlane::Organization if the page is genuinely organization-only.',
                $route,
                $area->value,
            ));
        }

        $page = new ConsolePage($area, $route, $label, $feature, $order, $only);

        $this->pages[] = $page;

        if ($page->serves(ConsolePlane::Organization)) {
            // The area is fetched with the host's own label, icon and order (held on the
            // enum) rather than the module's idea of them. `DefaultNavRegistry::area()`
            // applies a passed label/icon as an OVERRIDE, so a module adding a page to
            // "Settings" was able to restyle the host's Settings area for every page of
            // the console — the whitelabel module did, swapping its icon.
            Console::nav()
                ->area($area->value, $area->organizationLabel(), $area->organizationIcon(), $area->organizationOrder())
                ->page($route, $label, $feature, $order);
        }
    }

    /**
     * Every declared page, in registration order.
     *
     * @return list<ConsolePage>
     */
    public function all(): array
    {
        return $this->pages;
    }

    /**
     * The pages a plane serves, ordered, and only where the module is switched on.
     *
     * @return list<ConsolePage>
     */
    public function forPlane(ConsolePlane $plane): array
    {
        $pages = array_values(array_filter(
            $this->pages,
            fn (ConsolePage $page): bool => $page->serves($plane) && Console::featureActive($page->feature),
        ));

        usort($pages, static fn (ConsolePage $a, ConsolePage $b): int => $a->order <=> $b->order);

        return $pages;
    }
}
