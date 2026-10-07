<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Console\ConsoleRoutes;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\CustomerConsole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE DOOR HALF OF {@see CustomerConsole}: a page a customer's console does not offer is
 * not there, rather than merely unlinked.
 *
 * On EVERY organization-console route — the host's own group in `routes/web.php` and the
 * stacks {@see ConsoleRoutes} builds for modules — rather than on a
 * hand-picked list of them. The list of what a customer's console keeps lives in one
 * place, and this asks it by route name; a second list here, of routes to refuse, is the
 * one that would go stale the first time somebody added a webhook action and did not know
 * this file existed.
 *
 * After `platform.auth`, because the question is about WHO is acting as well as where: an
 * operator keeps the full console wherever they look ({@see ConsoleScope::atCustomerAltitude()}),
 * and before the session is resolved nobody is anyone. A visitor with no session is
 * therefore sent to sign in first and refused after, which discloses nothing the sign-in
 * page did not.
 *
 * 404 rather than 403, for the reason {@see RequireMultiTenant} gives: the page does not
 * exist on this console, and a refusal would say it does and that somebody could be let in.
 */
final class EnforceCustomerConsole
{
    public function __construct(private readonly ConsoleScope $scope) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route()?->getName();

        abort_if(
            is_string($route) && ! CustomerConsole::servesRoute($route) && $this->scope->atCustomerAltitude(),
            404,
        );

        return $next($request);
    }
}
