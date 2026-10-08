<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\OrganizationHeader;
use App\Platform\Console\OrganizationTabs;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * `console.org` — THE ORGANIZATION A PAGE ACTS ON, FROM ITS URL.
 *
 * Every route under `/admin/organizations/{organization}/…` passes through here. The id is
 * checked against THIS environment — the model's environment scope makes another
 * environment's id, a deleted row and a made-up one the same 404 — and bound into
 * {@see ConsoleScope} for the rest of the request, so everything that asks "which
 * organization" (the page, its entitlement checks, the action a write runs) gets the URL's
 * answer and no other.
 *
 * It replaced an "acting organization" the console header kept in the session: the same
 * question answered from state nobody could see, which made a pasted link open a different
 * page for its reader and let a second tab retarget the first.
 *
 * TWO THINGS ARE DONE TO THE ROUTE so a controller written for the environment-wide console
 * can serve a tab of the hub unchanged:
 *
 *  - the parameter is FORGOTTEN once bound. Laravel hands route parameters to a controller
 *    method by position, so `verifyDomain(string $domain)` would otherwise receive the
 *    organization id as its domain;
 *  - it becomes a URL DEFAULT, so the same controller's `route('…domains.verify', $id)`
 *    builds the address under this organization without having to know there is one.
 *
 * And a page drawn here gets the hub's header (`organizationHub`) to wrap itself in.
 */
final class BindConsoleOrganization
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $organizationId = $route?->parameter('organization');

        abort_unless(is_string($organizationId) && $organizationId !== '' && strlen($organizationId) <= 64, 404);

        $scope = app(ConsoleScope::class);

        if ($scope->plane() === ConsolePlane::Organization) {
            // Not a choice on this plane: the URL may only name the member's own, and any
            // other is a page that does not exist for them.
            abort_unless($scope->organizationId() === $organizationId, 404);
        } else {
            abort_unless($scope->bindOrganization($organizationId), 404);
        }

        URL::defaults(['organization' => $organizationId]);
        $route->forgetParameter('organization');

        if ($request->isMethod('GET') && $scope->plane() === ConsolePlane::Environment) {
            $tab = OrganizationTabs::currentFor($route->getName());

            Inertia::share('organizationHub', static fn () => app(OrganizationHeader::class)->for($organizationId, $tab)?->toArray());
        }

        try {
            return $next($request);
        } finally {
            // Bound for THIS request and no other. A long-lived process (Octane, a queue
            // worker, the test suite) reuses the container and the URL generator, and an
            // organization still bound when the next request arrives is exactly the hidden
            // "acting organization" this replaced.
            if ($scope->plane() === ConsolePlane::Environment) {
                $scope->releaseOrganization();
            }

            URL::defaults(['organization' => null]);
            Inertia::share('organizationHub', null);
        }
    }
}
