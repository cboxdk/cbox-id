<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\RedirectOutsideInertia;
use App\Http\Middleware\TrustHostsExceptHealth;
use App\Platform\Console\HandoffTarget;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * WHICH ROUTE A PATH LANDS ON — asked of the real router, from the origin of the request
 * being served.
 *
 * Two places need it: {@see HandoffTarget}, which only lets the environment switcher carry
 * a page this console actually serves, and {@see RedirectOutsideInertia}, which decides
 * whether a redirect lands on a page the Inertia client can mount. Both used to build the
 * probe with `Request::create($path)`, and that is the bug this class exists to stop
 * coming back.
 *
 * `Request::create('/admin/users')` is a request for `http://localhost/admin/users`. In
 * production that host is untrusted: {@see TrustHostsExceptHealth} installs the
 * deployment's host patterns on Symfony's Request STATICALLY, so they apply to every
 * request object built afterwards — including a synthetic one — and the first
 * `getHost()` on it throws `SuspiciousOperationException`, which Laravel renders as a bare
 * "400 Bad Request". With routes cached (the image's `LARAVEL_AUTO_OPTIMIZE` does
 * `route:cache` at start) the matcher is Laravel's `CompiledRouteCollection`, which builds
 * a Symfony `RequestContext` from the probe and so calls `getHost()` on every match.
 *
 * The result, on cboxid.com: every console page that draws the environment switcher —
 * every environment-console page but its home, and the workspace pages that offer an
 * environment — answered 400 for everybody. None of the suite could see it: `TrustHosts`
 * is inert under `runningUnitTests()`, tests do not cache routes, and their host IS
 * `localhost`.
 *
 * So the probe is rooted at the scheme and host of the request being served — a host that
 * has already passed the trusted-host check, or this code would not be running. A host
 * that still fails (a misconfiguration, or a console command with no request) is treated
 * as "no route", the same answer an unroutable path gets, rather than a 400 on an
 * unrelated page.
 */
final readonly class RouteLookup
{
    public function __construct(private Router $router) {}

    /**
     * The GET route `$target` resolves to — a path, or an absolute URL whose own origin is
     * kept — or null when nothing here answers it.
     */
    public function get(string $target): ?Route
    {
        try {
            return $this->router->getRoutes()->match(Request::create($this->absolute($target), 'GET'));
        } catch (HttpExceptionInterface|SuspiciousOperationException) {
            return null;
        }
    }

    /** A bare path, put on the origin of the request being served. */
    private function absolute(string $target): string
    {
        if (parse_url($target, PHP_URL_HOST) !== null || ! str_starts_with($target, '/')) {
            return $target;
        }

        $current = app()->bound('request') ? app('request') : null;

        if (! $current instanceof Request) {
            return $target;
        }

        try {
            return $current->getSchemeAndHttpHost().$target;
        } catch (SuspiciousOperationException) {
            return $target;
        }
    }
}
