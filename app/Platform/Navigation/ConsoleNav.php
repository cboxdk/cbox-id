<?php

declare(strict_types=1);

namespace App\Platform\Navigation;

/**
 * One plane's navigation, and the only description of it.
 *
 * These lists used to live as nested arrays inside the Blade layouts, which meant the
 * only code that could read them was the sidebar rendering them. Anything else that
 * needed to know where a page sat — the eyebrow above a page title, a breadcrumb, a
 * test asserting a route is reachable — had to be told separately, and the two drifted:
 * pages shipped with an eyebrow reading "Security" while the sidebar that got you there
 * said "Developers".
 */
readonly class ConsoleNav
{
    /** @var list<NavArea> */
    public array $areas;

    public function __construct(NavArea ...$areas)
    {
        $this->areas = array_values($areas);
    }

    /**
     * The area owning a route, or null if this plane does not serve it.
     */
    public function areaFor(string $route): ?NavArea
    {
        foreach ($this->areas as $area) {
            if ($area->owns($route)) {
                return $area;
            }
        }

        return null;
    }

    /**
     * The area the current request sits in — falling back to the first, so the rail is
     * never rendered with nothing selected on a page that isn't in the nav at all.
     */
    public function currentArea(): NavArea
    {
        foreach ($this->areas as $area) {
            if ($area->isCurrent()) {
                return $area;
            }
        }

        return $this->areas[0];
    }

    /** @return list<string> every route named in this plane's navigation */
    public function routes(): array
    {
        $routes = [];

        foreach ($this->areas as $area) {
            foreach ($area->pages as $page) {
                $routes[] = $page->route;
            }
        }

        return $routes;
    }

    /**
     * The projection the rail component consumes. Kept here rather than in the layout
     * so all four planes' rails are built by the same code.
     *
     * @return list<array{key: string, label: string, icon: string, href: string, active: bool, current: bool}>
     */
    public function rail(): array
    {
        $current = $this->currentArea();

        return array_map(fn (NavArea $area): array => [
            'key' => $area->label,
            'label' => $area->label,
            'icon' => $area->icon,
            'href' => $area->href(),
            'active' => $area->label === $current->label,
            'current' => $area->label === $current->label && $area->isLeaf(),
        ], $this->areas);
    }

    /**
     * The current area's pages, for the second tier.
     *
     * @return list<array{href: string, label: string, active: bool}>
     */
    public function subnav(): array
    {
        return array_map(fn (NavPage $page): array => [
            'href' => $page->href(),
            'label' => $page->label,
            'active' => $page->isCurrent(),
        ], $this->currentArea()->pages);
    }

    /**
     * The flat grouped list the mobile sheet renders — a rail is a pointer affordance,
     * not a touch one, so small screens keep every group open.
     *
     * @return list<array{label: string, icon: string, pages: list<array{route: string, label: string}>}>
     */
    public function groups(): array
    {
        return array_map(fn (NavArea $area): array => [
            'label' => $area->label,
            'icon' => $area->icon,
            'pages' => array_map(fn (NavPage $page): array => [
                'route' => $page->route,
                'label' => $page->label,
            ], $area->pages),
        ], $this->areas);
    }
}
