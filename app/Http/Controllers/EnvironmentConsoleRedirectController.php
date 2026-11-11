<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Console\EnvironmentRefusedController;
use App\Http\Middleware\ResolveEnvironmentFromPath;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\Deprecations\RetiredNameUse;
use App\Platform\NativeProduct\NativeEnvironmentAccess;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The environment console's OLD addresses, kept working while they are still used.
 *
 * - `{environment host}/admin/…` and `{root host}/admin/…` (the root's own console) answer
 *   with a redirect to the same page on the app host: `/orgs/{org}/projects/{project}/
 *   environments/{environment}/…`, or `/admin/environment/…` for the root.
 * - `/open/{environment}` on the app host, which used to mint a signed handoff into the
 *   environment's own host, now sends the browser straight to the console's path — or,
 *   for somebody who does not administer that environment, the page that says so.
 *
 * Only a redirect: nothing here signs anybody in or decides access. The page redirected to
 * asks every question itself ({@see ResolveEnvironmentFromPath}). A WRITE from a v2 tab still
 * open on the old address ({@see write()}) keeps its method where it can (a 308 on the same
 * host); across hosts the tab is sent to the page instead, because a browser will not carry
 * a cross-origin XHR through a redirect without CORS, which an identity provider does not
 * open for its console.
 * Each answer carries `Deprecation` and is counted ({@see RetiredNameUse}), so whoever still
 * uses one can be found and told; the old addresses go once they have seen no traffic for
 * seven days. Temporary redirects, so a browser keeps asking and the count stays true.
 */
final class EnvironmentConsoleRedirectController extends Controller
{
    public function __construct(
        private readonly EnvironmentConsolePath $paths,
        private readonly PlaneResolver $planes,
    ) {}

    /** `{host}/admin/{path?}` → the app host. */
    public function __invoke(Request $request, EnvironmentContext $environments, ?string $path = null): Response
    {
        $environment = $environments->current()?->environmentKey();
        $console = $environment === null ? null : $this->paths->for($environment);

        abort_if($console === null, 404);

        RetiredNameUse::record('console_url', '/admin'.($path === null || $path === '' ? '' : '/'.trim($path, '/')), '/'.$console, ['host' => $request->getHost()]);

        // 302, not 301: a browser caches a permanent redirect and stops asking, and then the
        // old address's traffic — what decides when it may go — could no longer be counted.
        return $this->deprecated(redirect()->away($this->appHostUrl($console, $path).$this->query($request), 302));
    }

    /**
     * A v2 tab's PATCH, PUT, POST or DELETE to `{host}/admin/…`, which is not served there
     * any more.
     *
     *  - Same host (the console's own host): a 308 to the same page, so the method and body
     *    go along, as every other moved write does.
     *  - Another host: an Inertia visit gets a 409 with `X-Inertia-Location`, and the tab
     *    opens the nearest page of that console that exists, where the change can be made
     *    again. A redirect would degrade into a failed cross-origin request and a page that
     *    silently did nothing.
     *  - Anything else is the 404 it was: nothing but the console's own tab ever wrote here.
     */
    public function write(Request $request, EnvironmentContext $environments, ?string $path = null): Response
    {
        $environment = $environments->current()?->environmentKey();
        $console = $environment === null ? null : $this->paths->for($environment);

        abort_if($console === null, 404);

        $page = $path === null ? '' : trim($path, '/');
        $host = $this->planes->consoleHost() ?? $request->getHost();
        $retired = $request->method().' /admin'.($page === '' ? '' : '/'.$page);

        if (strcasecmp($host, $request->getHost()) === 0) {
            RetiredNameUse::record('console_url', $retired, $request->method().' /'.$console, ['host' => $request->getHost()]);

            return $this->deprecated(redirect()->away($this->appHostUrl($console, $page).$this->query($request), 308));
        }

        abort_unless($request->header('X-Inertia') !== null, 404);

        $landing = $this->nearestPage($console, $page);

        RetiredNameUse::record('console_url', $retired, 'GET /'.$console.($landing === '' ? '' : '/'.$landing), ['host' => $request->getHost()]);

        $response = Inertia::location($this->appHostUrl($console, $landing));
        $response->headers->set('Deprecation', 'true');

        return $response;
    }

    /**
     * The longest leading part of `$page` that is a page of the console (`users/{id}` for a
     * write to `users/{id}/sessions/{session}`), or '' for its home.
     */
    private function nearestPage(string $console, string $page): string
    {
        $segments = $page === '' ? [] : explode('/', $page);

        while ($segments !== []) {
            $candidate = implode('/', $segments);

            try {
                $route = app('router')->getRoutes()->match(Request::create('/'.$console.'/'.$candidate, 'GET'));

                if (! $route->isFallback && str_starts_with($route->uri(), '{'.EnvironmentConsolePath::PARAMETER.'}')) {
                    return $candidate;
                }
            } catch (HttpExceptionInterface) {
                // Not a page: try its parent.
            }

            array_pop($segments);
        }

        return '';
    }

    /** `/open/{environment}` on the app host → the environment's console path. */
    public function open(Request $request, string $environment, ConsoleScope $scope): Response
    {
        if ($request->header('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }

        try {
            $organizationId = $scope->organizationId();
        } catch (AuthorizationException) {
            $organizationId = null;
        }

        $subjectId = $scope->actorId();

        if ($subjectId === '') {
            return redirect()->route('login');
        }

        if ($organizationId === null
            || $scope->capabilities()?->canReadEnvironments() !== true
            || ! in_array($environment, app(NativeEnvironmentAccess::class)->ids($organizationId, $subjectId), true)) {
            return app(EnvironmentRefusedController::class)->show($request, $environment);
        }

        $console = $this->paths->for($environment);

        abort_if($console === null, 404);

        // A page inside the console, from an old "open with ?to=/admin/users" link.
        $to = $request->query('to');
        $page = is_string($to) && preg_match('#\A/admin((?:/[A-Za-z0-9_-]+)*)\z#', $to, $match) === 1 ? ltrim($match[1], '/') : null;

        RetiredNameUse::record('console_url', '/open/{environment}', '/'.$console);

        return $this->deprecated(redirect()->to('/'.$console.($page === null || $page === '' ? '' : '/'.$page), 302));
    }

    private function appHostUrl(string $console, ?string $path): string
    {
        $host = $this->planes->consoleHost() ?? request()->getHost();
        $page = $path === null ? '' : trim($path, '/');

        return request()->getScheme().'://'.$host.'/'.$console.($page === '' ? '' : '/'.$page);
    }

    private function query(Request $request): string
    {
        $query = $request->getQueryString();

        return $query === null || $query === '' ? '' : '?'.$query;
    }

    private function deprecated(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Deprecation', 'true');

        return $response;
    }
}
