<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\TrustHostsExceptHealth;
use App\Platform\TrustedHosts;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * THE TWO THINGS PRODUCTION DOES THAT A TEST DOES NOT — done here, on purpose.
 *
 * Both hid a production-only 400 on every environment-console page from a green suite:
 *
 *  1. ROUTES ARE CACHED. The image's `LARAVEL_AUTO_OPTIMIZE` runs `route:cache` at
 *     container start, so production matches with Laravel's `CompiledRouteCollection` —
 *     a Symfony matcher that builds a `RequestContext` from the request and therefore
 *     reads its host. A test matches with the plain `RouteCollection`, which never does
 *     for a route without a domain.
 *
 *  2. THE HOST ALLOW-LIST IS ENFORCED. {@see TrustHostsExceptHealth} installs the
 *     deployment's host patterns on Symfony's `Request` — statically, so they govern every
 *     request object built afterwards, synthetic ones included. Laravel's `TrustHosts` is
 *     inert under `runningUnitTests()`, so in a test nothing is ever untrusted.
 *
 * Together: code that built `Request::create('/admin/users')` to ask the router a question
 * got a request for `http://localhost/…`, the compiled matcher asked it for its host, and
 * Symfony refused `localhost` with a `SuspiciousOperationException` — a bare "400 Bad
 * Request" on cboxid.com, and a 200 in every test.
 *
 * Call {@see self::reset()} after the test: the trusted-host list is process-global, and a
 * parallel worker runs the next test file in the same process.
 */
final class ProductionShape
{
    /** Compile the routes exactly as `route:cache` does, and serve from the result. */
    public static function cacheRoutes(): void
    {
        $router = app(Router::class);
        $routes = $router->getRoutes();

        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        foreach ($routes as $route) {
            $route->prepareForSerialization();
        }

        $router->setCompiledRoutes($routes->compile());
    }

    /**
     * Enforce the trusted-host list the deployment derives, as the middleware would
     * outside a test: the {@see TrustedHosts} patterns plus `app.url` and its subdomains
     * (the middleware's `subdomains: true`).
     */
    public static function enforceTrustedHosts(): void
    {
        $patterns = app(TrustedHosts::class)->patterns();
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($appHost) && $appHost !== '') {
            $patterns[] = '^(.+\.)?'.preg_quote($appHost).'$';
        }

        Request::setTrustedHosts($patterns);
    }

    /** Back to a process that trusts every host, as the rest of the suite expects. */
    public static function reset(): void
    {
        Request::setTrustedHosts([]);
    }
}
