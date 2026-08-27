<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\EnforceImpersonationWindow;
use Illuminate\Support\Facades\Route;

/**
 * How a module routes a console page — on both planes, at one component.
 *
 * A module's routes file used to spell out the middleware stack itself, and every one of
 * the six spelled out the SAME stack: `web`, the console plane, the impersonation window,
 * `platform.auth`, `console.feature:x`. That stack is the organization plane. None of
 * them wrote the environment plane's, which is a different four — `env.admin` instead of
 * a subject session, under the `/admin` prefix, with no impersonation window because an
 * environment administrator is not impersonating anyone.
 *
 * So the mistake was not that six authors forgot a stack; it was that writing a page
 * meant writing a stack, and one of the two was the one everybody had already copied.
 * Here the page is the argument and the stacks are not — {@see page()} registers both
 * from ONE component, which also makes "the same page on both planes" true by
 * construction rather than by a comparison that has to be run.
 */
final class ConsoleRoutes
{
    /**
     * Route a console page on BOTH planes, from the same controller.
     *
     * @param  string  $feature  the console-kit feature; the page 404s where it is off,
     *                           on both planes, so a disabled module has no reachable URL
     *                           rather than merely an unlinked one
     * @param  string  $uri  the organization plane's path
     * @param  string  $name  the organization plane's route name; the environment plane's
     *                        is that with an `environment.` prefix
     * @param  array{0: class-string, 1: string}|class-string  $component  the page's controller — an
     *                                                                     invokable class, or a [class, method]
     *                                                                     pair — rendered through Inertia
     * @param  string|null  $environmentUri  the environment plane's path under `/admin`,
     *                                       when the two planes spell it differently (as
     *                                       the merged core capabilities do — `/connections`
     *                                       and `/admin/single-sign-on`)
     */
    public static function page(
        string $feature,
        string $uri,
        array|string $component,
        string $name,
        ?string $environmentUri = null,
    ): void {
        self::organizationPage($feature, $uri, $component, $name);

        Route::middleware([
            'web',
            // `plane:environment` for the same reason the host's own environment console
            // carries it: this door is opened by an ACCOUNT reaching into an environment
            // from the account plane, so it is absent on the account plane itself. The
            // gate that follows is the env-admin session — a subject session grants
            // nothing here.
            'plane:environment',
            // …and the same multi-tenant gate, which this stack was missing. 55 of the
            // host's 64 environment routes carry it and these did not, so on a
            // single-tenant deployment a module's environment page answered a redirect
            // to a sign-in it can never pass while every sibling page 404'd. That
            // difference is the disclosure `RequireMultiTenant` exists to prevent: it
            // answers 404 rather than 403 precisely so an unauthenticated caller cannot
            // learn which shape this deployment runs. A door that cannot open should not
            // be a door, and it should not be a differently-shaped door either.
            'multi.tenant',
            'env.admin',
            'console.feature:'.$feature,
        ])->prefix('admin')->group(function () use ($environmentUri, $uri, $component, $name): void {
            self::get($environmentUri ?? $uri, $component)->name('environment.'.$name);
        });
    }

    /**
     * Route a console page on the ORGANIZATION plane only.
     *
     * For a page that genuinely belongs to one plane — a subject's own self-service
     * page, whose every read and write is keyed to the signed-in subject. An environment
     * administrator is a control-plane identity holding an account membership, never a
     * subject inside the environment they administer, so there is no "my devices" for
     * them to be shown: the page would render an empty shell that could never fill.
     */
    /**
     * @param  array{0: class-string, 1: string}|class-string  $component
     */
    public static function organizationPage(string $feature, string $uri, array|string $component, string $name): void
    {
        Route::middleware([
            'web',
            // Every host, the platform root included — a console page is a console page
            // wherever the console is served.
            'plane:console',
            // ENFORCED rather than inherited — without it, an impersonation that outlived
            // its 30-minute box keeps reading these pages, because reads sit on the call
            // guard's allowlist and nothing else stops them.
            EnforceImpersonationWindow::class,
            'platform.auth',
            'console.feature:'.$feature,
        ])->group(function () use ($uri, $component, $name): void {
            self::get($uri, $component)->name($name);
        });
    }

    /**
     * Route a console ACTION on both planes — a write a page performs on itself.
     *
     * Under Volt there was nothing to register: every mutation was a component method
     * reached through one shared endpoint, which is exactly why the impersonation guard had
     * to live at that seam rather than on a route. A ported page's write is its own request
     * with its own verb, so it needs its own route on each plane — and it needs the SAME
     * middleware the page it belongs to carries, which is the whole reason this sits beside
     * {@see page()} rather than being spelled out in each module.
     *
     * @param  string  $verb  post|patch|put|delete
     * @param  array{0: class-string, 1: string}|class-string  $action  a controller action
     */
    public static function action(
        string $feature,
        string $verb,
        string $uri,
        array|string $action,
        string $name,
        ?string $environmentUri = null,
    ): void {
        self::organizationAction($feature, $verb, $uri, $action, $name);

        Route::middleware([
            'web',
            'plane:environment',
            'multi.tenant',
            'env.admin',
            'console.feature:'.$feature,
        ])->prefix('admin')->group(function () use ($verb, $environmentUri, $uri, $action, $name): void {
            self::verb($verb, $environmentUri ?? $uri, $action)->name('environment.'.$name);
        });
    }

    /**
     * Route a console ACTION on the ORGANIZATION plane only.
     *
     * The write half of {@see organizationPage()}, and it exists for the same reason: a
     * page whose every read is keyed to the signed-in SUBJECT has no meaning on the
     * environment plane, where an administrator is a control-plane identity rather than a
     * subject inside the environment. Registering its write there anyway would put a door
     * on a corridor with no room behind it — reachable, refused for the wrong reason, and
     * one more URL for a sweep to have to explain.
     *
     * @param  string  $verb  post|patch|put|delete
     * @param  array{0: class-string, 1: string}|class-string  $action  a controller action
     */
    public static function organizationAction(
        string $feature,
        string $verb,
        string $uri,
        array|string $action,
        string $name,
    ): void {
        Route::middleware([
            'web',
            'plane:console',
            EnforceImpersonationWindow::class,
            'platform.auth',
            'console.feature:'.$feature,
        ])->group(function () use ($verb, $uri, $action, $name): void {
            self::verb($verb, $uri, $action)->name($name);
        });
    }

    /**
     * One route, at the verb this action uses.
     *
     * `Route::match()` rather than the dynamic `Route::{$verb}()` the facade would accept: a
     * variable method on a facade is untyped all the way down, so nothing could tell that
     * what came back was a route to name.
     *
     * @param  array{0: class-string, 1: string}|class-string  $action
     */
    private static function verb(string $verb, string $uri, array|string $action): \Illuminate\Routing\Route
    {
        return Route::match([$verb], $uri, $action);
    }

    /**
     * @param  array{0: class-string, 1: string}|class-string  $component
     */
    private static function get(string $uri, array|string $component): \Illuminate\Routing\Route
    {
        return Route::get($uri, $component);
    }
}
