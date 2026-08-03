<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\EnforceImpersonationWindow;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/**
 * How a module routes a console page — on both planes, at one component.
 *
 * A module's routes file used to spell out the middleware stack itself, and every one of
 * the six spelled out the SAME stack: `web`, `plane:subject`, the impersonation window,
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
     * Route a console page on BOTH planes, at the same Volt component.
     *
     * @param  string  $feature  the console-kit feature; the page 404s where it is off,
     *                           on both planes, so a disabled module has no reachable URL
     *                           rather than merely an unlinked one
     * @param  string  $uri  the organization plane's path
     * @param  string  $name  the organization plane's route name; the environment plane's
     *                        is that with an `environment.` prefix
     * @param  string|null  $environmentUri  the environment plane's path under `/admin`,
     *                                       when the two planes spell it differently (as
     *                                       the merged core capabilities do — `/connections`
     *                                       and `/admin/single-sign-on`)
     */
    public static function page(
        string $feature,
        string $uri,
        string $component,
        string $name,
        ?string $environmentUri = null,
    ): void {
        self::organizationPage($feature, $uri, $component, $name);

        Route::middleware([
            'web',
            // `plane:subject` for the same reason the host's own environment console
            // carries it: the admin door lives on a TENANT host, never on the account
            // root. The gate that follows is the env-admin session — a subject session
            // grants nothing here.
            'plane:subject',
            'env.admin',
            'console.feature:'.$feature,
        ])->prefix('admin')->group(function () use ($environmentUri, $uri, $component, $name): void {
            Volt::route($environmentUri ?? $uri, $component)->name('environment.'.$name);
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
    public static function organizationPage(string $feature, string $uri, string $component, string $name): void
    {
        Route::middleware([
            'web',
            'plane:subject',
            // ENFORCED rather than inherited — without it, an impersonation that outlived
            // its 30-minute box keeps reading these pages, because reads sit on the call
            // guard's allowlist and nothing else stops them.
            EnforceImpersonationWindow::class,
            'platform.auth',
            'console.feature:'.$feature,
        ])->group(function () use ($uri, $component, $name): void {
            Volt::route($uri, $component)->name($name);
        });
    }
}
