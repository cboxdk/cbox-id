<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A REDIRECT THAT LEAVES THE INERTIA APP HAS TO SAY SO.
 *
 * Inertia's client follows a redirect with an XHR and expects the page object back. Point
 * one at something that answers with ordinary HTML and the client cannot render it — it
 * opens its error modal over the page instead, which is what a person signing in saw the
 * first time a React form redirected to a console page Volt still serves.
 *
 * The protocol already has the answer: a 409 carrying `X-Inertia-Location`, which tells
 * the browser to do a real navigation. `Inertia::location()` produces it. What was missing
 * is knowing WHEN, and leaving that to each controller means every redirect is a decision
 * somebody can get wrong — silently, because a test never renders the response it
 * followed.
 *
 * So it is decided here, from the destination itself. Two cases:
 *
 *  - ANOTHER ORIGIN. An identity provider's redirect endpoint, a signed hand-off to a
 *    tenant's own host. An XHR cannot follow those at all: the fetch fails on CORS and
 *    the person is left on a page that looks like nothing happened.
 *
 *  - A ROUTE THIS APPLICATION SERVES WITH A CLOSURE, which on this codebase means Volt.
 *    Transitional, and the reason this middleware exists at all — it goes when the last
 *    Volt page does, leaving only the cross-origin half.
 */
final class RedirectOutsideInertia
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only an Inertia visit is affected: an ordinary browser navigation follows a
        // redirect by itself and needs nothing from us.
        if (! $request->header('X-Inertia') || ! $response instanceof RedirectResponse) {
            return $response;
        }

        $target = $response->getTargetUrl();

        if ($this->rendersInertia($request, $target)) {
            return $response;
        }

        return Inertia::location($target);
    }

    private function rendersInertia(Request $request, string $target): bool
    {
        if (! $this->sameOrigin($request, $target)) {
            return false;
        }

        $route = $this->routeFor($target);

        // Unroutable — a static file, a path this deployment does not serve. Whatever it
        // is, it is not a page this client can mount, so let the browser go and get it.
        if ($route === null) {
            return false;
        }

        // A closure action carries nothing to inspect and, on this codebase, is a Volt
        // page. A controller route is one of ours and answers with a page object.
        return $route->getActionName() !== 'Closure';
    }

    private function sameOrigin(Request $request, string $target): bool
    {
        $host = parse_url($target, PHP_URL_HOST);

        // A relative target never left. Laravel's own redirects are absolute, but a
        // controller may hand back a path.
        if (! is_string($host)) {
            return true;
        }

        return $host === $request->getHost();
    }

    private function routeFor(string $target): ?Route
    {
        try {
            return Router::getRoutes()->match(Request::create($target, 'GET'));
        } catch (HttpException) {
            // No match, or matched on a different verb. Either way there is no page here
            // to mount, so the caller treats it as leaving.
            return null;
        }
    }
}
