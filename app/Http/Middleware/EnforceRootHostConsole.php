<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Console\ConfiguredInEnvironmentController;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\RootHostConsole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE DOOR HALF OF {@see RootHostConsole}: on the platform root's host, a page that sets
 * something an environment owns is not served as the workspace's.
 *
 *  - An OPERATOR asking for it is sent to the same page of the platform root's own
 *    environment console — `cboxid.com/social-sign-in` → `cboxid.com/admin/social-sign-in`
 *    — with the same parameters and query: the platform's sign-in is what they meant.
 *  - Anybody else is shown, at that URL, where it is set: in each environment's console,
 *    with a link to the same page there ({@see ConfiguredInEnvironmentController}).
 *  - A write is refused with a 404, so nothing on this host can configure a workspace's
 *    organization as though it were an environment.
 *
 * After `platform.auth`, like {@see EnforceCustomerConsole}: who is asking decides where
 * they are sent, and before the session is resolved nobody is anyone.
 */
final class EnforceRootHostConsole
{
    public function __construct(
        private readonly RootHostConsole $console,
        private readonly ConsoleScope $scope,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $name = $route?->getName();

        if (! is_string($name) || ! $this->console->applies()) {
            return $next($request);
        }

        $operator = $this->scope->isPlatformOperator();

        if (RootHostConsole::keepsRoute($name, $operator)) {
            return $next($request);
        }

        abort_unless($request->isMethod('GET') || $request->isMethod('HEAD'), 404);

        $twin = RootHostConsole::environmentTwin($name);

        if ($twin !== null && $operator) {
            $target = route($twin, $route->parameters());
            $query = $request->getQueryString();

            return redirect()->to($query === null || $query === '' ? $target : $target.'?'.$query);
        }

        return app(ConfiguredInEnvironmentController::class)->show($name, $twin)->toResponse($request);
    }
}
