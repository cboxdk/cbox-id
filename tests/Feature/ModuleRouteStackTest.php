<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * Every module console route carries the same middleware the host's own console routes do.
 *
 * Five of the six modules shipped with only `['web', 'platform.auth', 'console.feature:x']`
 * — a session exists and the flag is on, and nothing else. Devices had the full stack and a
 * comment explaining why. The two that were missing are not decorative:
 *
 *  - `plane:subject` confines these pages to a tenant host. Without it they answer on the
 *    account root, unlike every other console route.
 *  - `EnforceImpersonationWindow` terminates an impersonation that has outlived its
 *    30-minute box. Without it, an impersonator keeps reading risk events, audit trails,
 *    analytics and connectors past the deadline — reads sit on ImpersonationCallGuard's
 *    allowlist, so nothing else refuses them.
 *
 * Derived from the router rather than a hand-kept list, so the sixth module cannot ship
 * without them and have nobody notice.
 *
 * Matched against BOTH the alias and the class name, because `gatherMiddleware()` returns
 * whichever form the route was declared with — a check that knew only one form would pass
 * on the routes declared the other way, which is its own kind of not checking.
 */
it('gives every module console route the host console stack', function (): void {
    $modules = array_map('basename', array_filter(glob(base_path('modules/*')) ?: [], 'is_dir'));

    expect(count($modules))->toBeGreaterThan(3, 'module discovery broke');

    /** @var array<string, list<string>> $required  label => the spellings that satisfy it */
    $required = [
        'the subject plane' => ['plane:subject', 'EnforcePlane:subject'],
        'the impersonation window' => ['EnforceImpersonationWindow'],
    ];

    $missing = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();
        $uri = (string) $route->uri();

        // Console pages only. A module's API surface (the devices app's REST routes)
        // authenticates with a token and is deliberately not on the subject plane.
        if ($name === '' || str_starts_with($uri, 'api/') || str_starts_with($uri, '.well-known')) {
            continue;
        }

        $owned = collect($modules)->contains(
            fn (string $m): bool => str_starts_with($name, $m.'.') || str_starts_with($name, str_replace('-', '', $m).'.')
        );

        if (! $owned) {
            continue;
        }

        $checked++;
        $middleware = implode(' ', array_map(
            fn (string $m): string => str_contains($m, '\\') ? class_basename($m) : $m,
            $route->gatherMiddleware(),
        ));

        foreach ($required as $label => $spellings) {
            if (! collect($spellings)->contains(fn (string $s): bool => str_contains($middleware, $s))) {
                $missing[] = "{$name} lacks {$label}";
            }
        }
    }

    // Matching no routes is how this guard would report clean while checking nothing.
    expect($checked)->toBeGreaterThan(5, "the sweep examined {$checked} module routes");
    expect(array_unique($missing))->toBe([], implode('; ', array_unique($missing)));
});
