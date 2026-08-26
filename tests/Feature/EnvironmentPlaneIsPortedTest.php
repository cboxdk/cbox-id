<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * EVERY PAGE UNDER `/admin` IS SERVED BY A CONTROLLER.
 *
 * The environment console was the last plane still on Volt, and this test was how it was
 * kept from sliding back one page at a time: it read each route's closure for a captured
 * component name and checked whether a blade of that name existed on disk.
 *
 * That check cannot fail any more — `resources/views/livewire` is gone, so the file test
 * inside it is always false and the sweep passes over anything. What it was really saying
 * is still worth saying, and can be asked directly: a page here is a CONTROLLER ACTION. A
 * closure serving a page is the shape every regression to a template-in-a-route takes,
 * whatever the template engine, so that is what is refused.
 */
it('serves every environment-plane page from a controller', function (): void {
    // Every module on, or a module's pages are not registered at all and this passes by
    // measuring a console with fewer doors than the real one.
    config([
        'id-analytics.enabled' => true,
        'compliance.enabled' => true,
        'connectors.enabled' => true,
        'id-devices.enabled' => true,
    ]);

    /*
     * The front door is a REDIRECT, not a page: `/admin/login` sends you to the platform
     * root's sign-in, because signing in is unified and this host is not where it happens.
     * A redirect has nothing to render and no controller to name.
     */
    $ceremony = ['admin.login'];

    $closures = [];
    $pages = 0;

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with((string) $route->uri(), 'admin') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $pages++;

        if (in_array($route->getName(), $ceremony, true)) {
            continue;
        }

        if ($route->getAction('uses') instanceof Closure) {
            $closures[] = $route->getName().' → '.$route->uri();
        }
    }

    // A FLOOR on the population, so a routes file that failed to load cannot report a clean
    // console by describing an empty one.
    expect($pages)->toBeGreaterThan(30, 'almost no environment routes were found — did the console stop registering?');

    expect($closures)->toBe(
        [],
        "these environment-plane pages are served by a closure rather than a controller:\n".implode("\n", $closures),
    );
});
