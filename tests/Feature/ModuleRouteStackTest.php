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
 *  - `plane:console` is the gate every other console route carries. Without it these pages
 *    answer wherever the router reaches them, including on hosts the deployment does not
 *    claim — the console gate is a host question, not a no-op.
 *  - `EnforceImpersonationWindow` terminates an impersonation that has outlived its
 *    30-minute box. Without it, an impersonator keeps reading risk events, audit trails,
 *    analytics and connectors past the deadline — the impersonation read-only rule refuses
 *    writes and lets every read through, so nothing else refuses them.
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
        'the console plane' => ['plane:console', 'EnforcePlane:console'],
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

/**
 * …and the same for the module pages on the ENVIRONMENT plane, which the sweep above
 * cannot see.
 *
 * That one matches a route whose NAME begins with the module's — `compliance.audit`.
 * Every module page is also routed on the environment plane, so eight routes fell outside
 * the pattern and were checked by nothing. They had drifted: 55 of the host's 64
 * environment routes carried `RequireMultiTenant` and these carried none of it, so on a
 * single-tenant deployment a module's environment page answered a redirect to a sign-in
 * nobody can pass while every sibling page 404'd. That difference is the disclosure the
 * middleware exists to prevent — it answers 404 rather than 403 exactly so an
 * unauthenticated caller cannot learn which shape the deployment runs from a status code.
 *
 * OWNERSHIP COMES FROM `RequireFeature`, NOT THE ROUTE NAME. Two of the eight —
 * `environment.analytics` and `environment.sign-in-activity` — are module pages whose
 * names carry no module prefix at all, so a name-based check counts them as HOST routes.
 * My first version of this test derived the requirement from "what every host route
 * carries", which meant those two misclassified routes both defined the rule and were
 * exempt from it: removing the middleware from all eight also removed it from the two
 * counted as host, the host stopped being unanimous, and the rule switched itself off.
 * The mutation caught it. `RequireFeature` is what actually marks a module page, and it
 * is read off the router rather than inferred from a naming convention nobody enforces.
 */
it('gives every module environment route the host environment stack', function (): void {
    /**
     * Stated rather than derived, and each with the reason it is not optional. A
     * requirement computed from the routes it polices can always be satisfied by changing
     * the routes, which is how the first version of this test disabled itself.
     */
    $required = [
        // The plane bulkhead. Without it these pages answer wherever the router reaches
        // them, on hosts this deployment does not claim.
        'the environment plane' => ['plane:environment', 'EnforcePlane:environment'],
        // 404 rather than 403 on a single-tenant deployment, so a caller cannot learn the
        // shape from a status code.
        'the multi-tenant gate' => ['multi.tenant', 'RequireMultiTenant'],
        // The env-admin session. A subject session grants nothing here.
        'the environment-admin session' => ['env.admin', 'AuthenticateEnvironmentAdmin'],
    ];

    $missing = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();
        $middleware = implode(' ', array_map(
            fn (string $m): string => str_contains($m, '\\') ? class_basename($m) : $m,
            $route->gatherMiddleware(),
        ));

        // A module console page is one gated on a module FEATURE. That is what the
        // registrar stamps on every page it routes, and unlike the route name it is not a
        // convention somebody can quietly not follow.
        $gatedOnAFeature = str_contains($middleware, 'console.feature:') || str_contains($middleware, 'RequireFeature');

        if (! str_starts_with($name, 'environment.') || ! $gatedOnAFeature) {
            continue;
        }

        $checked++;

        foreach ($required as $label => $spellings) {
            if (! collect($spellings)->contains(fn (string $s): bool => str_contains($middleware, $s))) {
                $missing[] = "{$name} lacks {$label}";
            }
        }
    }

    // Matching nothing is how this guard would report clean while checking nothing — and
    // the count is the number of module environment pages, which only grows.
    expect($checked)->toBeGreaterThan(5, "the sweep examined {$checked} module environment routes");
    expect(array_unique($missing))->toBe([], implode('; ', array_unique($missing)));
});
