<?php

declare(strict_types=1);

use App\Http\Middleware\EnforcePlane;
use App\Http\Middleware\ReadOnlyWhileImpersonating;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutedRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * A PAGE AND ITS WRITES MUST BE GUARDED THE SAME WAY.
 *
 * This file replaces `PersistentMiddlewareTest`, and the hazard is the same one wearing
 * different clothes. Under Livewire every console mutation was a component method POSTed
 * to the single `/livewire/update` endpoint, so route middleware never saw the individual
 * action: a guard that was not on Livewire's *persistent* list ran on the page load and
 * then stopped enforcing on every action the page performed. That was not theoretical —
 * with `env.admin` missing from that list the whole environment control plane answered
 * unauthenticated action requests, and the snapshot checksum was keyed on APP_KEY,
 * identical across tenant hosts, so a snapshot captured against one tenant replayed
 * against another's.
 *
 * There is no such endpoint any more. Every mutation is its own request with its own verb
 * through the full stack, so the framework-level half of that hazard is gone by
 * construction, and its test goes with it. What is NOT gone is the shape of the mistake:
 * the page route and the write route are now written separately, so a write can be
 * registered with a shorter stack than the pages it sits beside — and nothing about plain
 * routing notices. `ConsoleRoutes::action()` exists so the two are declared together;
 * this test is what makes using it non-optional.
 *
 * Derived from the ROUTER rather than from a hand-kept list, for the reason the file it
 * replaces gave: a list only guards the routes somebody remembered to add to it.
 */

/**
 * The app's own guards on a route, as the ROUTER resolves them.
 *
 * `gatherRouteMiddleware()` is the same call the kernel makes to build the pipeline, so it
 * expands groups, resolves aliases to classes and — the part that matters — subtracts
 * anything the route excluded. An earlier version of this read `Route::gatherMiddleware()`
 * and compared alias strings itself. That version passed with a guard deliberately removed
 * from a write by `withoutMiddleware()`: exclusions are recorded separately and the array
 * it read still listed the guard. A check that reads a different list from the one the
 * request runs is not checking the request.
 *
 * @return list<string>
 */
function appGuards(RoutedRoute $route): array
{
    $guards = [];

    foreach (app(Router::class)->gatherRouteMiddleware($route) as $middleware) {
        if (! is_string($middleware) || ! str_starts_with($middleware, 'App\\Http\\Middleware\\')) {
            continue;
        }

        // Parameters kept: `plane:console` and `plane:environment` are different gates, and
        // a write that swapped one for the other would otherwise read as equal to the page
        // it is weaker than.
        $guards[] = $middleware;
    }

    return array_values(array_unique($guards));
}

/**
 * Every stateful page route, by name.
 *
 * @return array<string, RoutedRoute>
 */
function namedPages(): array
{
    $pages = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        // Only stateful web routes: an API route authenticates with a bearer token and has
        // no page to be compared against.
        if ($name === null || ! in_array('web', $route->gatherMiddleware(), true)) {
            continue;
        }

        if (in_array('GET', $route->methods(), true)) {
            $pages[$name] = $route;
        }
    }

    return $pages;
}

/**
 * The pages a write belongs to: the ones sharing its LONGEST name prefix.
 *
 * Route names are hierarchical and that hierarchy is the answer. `devices.mine.remove`
 * belongs to `devices.mine` and not to the org-admin fleet inventory beside it, and
 * `environment.webhooks.update` belongs to the environment plane's webhook pages and not
 * to the organization plane's — the two planes answer to different authorities, and
 * comparing across them would demand the stricter plane's guards on the looser one.
 * Walking from the longest prefix down compares against the nearest pages that exist.
 *
 * @param  array<string, RoutedRoute>  $pages
 * @return list<RoutedRoute>
 */
function pagesFor(string $write, array $pages): array
{
    $segments = explode('.', $write);

    for ($depth = count($segments) - 1; $depth >= 1; $depth--) {
        $prefix = implode('.', array_slice($segments, 0, $depth));

        $matches = array_values(array_filter(
            $pages,
            fn (RoutedRoute $page, string $name): bool => $name === $prefix || str_starts_with($name, $prefix.'.'),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($matches !== []) {
            return $matches;
        }
    }

    return [];
}

/**
 * Guards a write may lack even though the pages beside it carry them, each with the reason.
 *
 * Keyed by route name, valued by the guard classes that are allowed to be absent — narrow
 * on purpose. Exempting a whole ROUTE would mean the next guard to go missing from it goes
 * missing in silence, which is the failure this file exists to prevent.
 *
 * @return array<string, list<class-string>>
 */
function looserByDesign(): array
{
    return [
        /*
         * Inbound federation, where this server is the RELYING party. `plane:issuer` gates
         * the ISSUER surface — a host that is not an identity provider must not answer as
         * one — and the SAML IdP endpoint beside it carries that gate for exactly that
         * reason. The ACS is the opposite role, and the account plane genuinely performs
         * it: home-realm discovery on `/login` sends a member to this URL on the very host
         * they are standing on, so gating it 404'd that callback and locked out any
         * organization with SSO required. The real boundary here is the environment scope
         * on `Connection`, which holds on either plane. See routes/web.php.
         */
        'sso.saml.acs' => [EnforcePlane::class],
    ];
}

it('guards every console write at least as tightly as the pages it sits beside', function (): void {
    $pages = namedPages();
    $weaker = [];
    $orphans = [];

    foreach (Route::getRoutes() as $write) {
        $name = $write->getName();

        if ($name === null || ! in_array('web', $write->gatherMiddleware(), true)) {
            continue;
        }

        if (in_array('GET', $write->methods(), true)) {
            continue;
        }

        $siblings = pagesFor($name, $pages);

        if ($siblings === []) {
            // A write with no page anywhere in its section — the sign-in POST and the
            // token endpoint are exactly that. Not wrong, but not something this test can
            // hold to anything, so it is counted rather than passed over in silence.
            $orphans[] = $name;

            continue;
        }

        /*
         * The INTERSECTION of the siblings' guards, not the union. A section whose detail
         * page carries a step-up its index page does not should not thereby demand that
         * step-up on every write in the section — that would be this test inventing
         * policy. What EVERY page in the section carries is the floor the section has
         * already agreed on, and a write below that floor is the finding.
         */
        $floor = appGuards($siblings[0]);

        foreach (array_slice($siblings, 1) as $page) {
            $floor = array_intersect($floor, appGuards($page));
        }

        $exempt = looserByDesign()[$name] ?? [];

        $missing = array_filter(
            array_diff($floor, appGuards($write)),
            fn (string $guard): bool => ! in_array(strtok($guard, ':'), $exempt, true),
        );

        if ($missing !== []) {
            $weaker[] = sprintf(
                '%s %s (%s) is missing %s',
                implode('|', array_diff($write->methods(), ['HEAD'])),
                $write->uri(),
                $name,
                implode(', ', array_map(fn (string $guard): string => class_basename(strtok($guard, ':')), $missing)),
            );
        }
    }

    expect($weaker)->toBe([], "These writes are guarded more loosely than the pages they sit beside:\n- ".implode("\n- ", $weaker));

    /*
     * NAMED, not counted. These are the writes this test cannot compare against anything,
     * so each one is exempt from the check above — and an exemption nobody listed is an
     * exemption nobody decided. Every entry here is a CEREMONY or a WAY OUT: an endpoint
     * a script calls rather than a page a person opens, which is why no page sits beside
     * it. A console write appearing in this list has a name that does not match its own
     * pages, and that is the finding, not the exemption.
     */
    sort($orphans);

    // Sorted, so the list reads as a set rather than as an order somebody has to maintain.
    expect($orphans)->toBe([
        // The browser-facing sign-in API the SDKs call — no server-rendered page at all.
        'frontend.sign-in',
        'frontend.sign-in.factor',
        'frontend.sign-in.passkey',
        'frontend.sign-in.passkey.options',
        // Ending a session, from either side of an impersonation.
        'impersonation.exit',
        'logout',
        // WebAuthn ceremonies: two round trips of JSON either side of the browser's own
        // credential prompt, issued by the page they belong to rather than routed to.
        'passkeys.login',
        'passkeys.login.options',
        'passkeys.register',
        'passkeys.register.options',
    ], 'Writes with no page to be compared against: '.implode(', ', $orphans));
})->group('security');

/**
 * The read-only rule for support impersonation is global or it is nothing.
 *
 * It answers "is this a write?" with the HTTP method, which is what lets it be global —
 * and being global is what buys deny-by-default: a route added tomorrow is refused while
 * impersonating without anybody having to think about it. Registered per group instead, it
 * would be a guard the next group does not carry. {@see ImpersonationReadOnlyTest} holds
 * what it actually refuses.
 */
it('applies the impersonation read-only rule to every stateful route', function (): void {
    $missing = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('web', $route->gatherMiddleware(), true)) {
            continue;
        }

        // RESOLVED, so this sees a route that excluded the rule as well as a rule dropped
        // from the group — `withoutMiddleware()` is per route and silent, and either way
        // the hole is the same size.
        if (! in_array(ReadOnlyWhileImpersonating::class, app(Router::class)->gatherRouteMiddleware($route), true)) {
            $missing[] = $route->uri();
        }
    }

    expect($missing)->toBe([], 'Stateful routes outside the impersonation read-only rule: '.implode(', ', array_unique($missing)));
})->group('security');
