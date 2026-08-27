<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Console\ConsoleScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MAY THIS PERSON CHANGE THINGS HERE, as opposed to look at them.
 *
 * Under Volt this question was asked inside every component, in `boot()` rather than
 * `mount()` — because `mount()` runs once and `boot()` runs on each action, and a page
 * whose authorization ran only at mount kept working for a downgraded administrator with
 * an open tab. Sixty-odd components had to remember that distinction, and the ones that
 * did not were a real defect for as long as nobody noticed.
 *
 * On a route it is asked once, before the controller exists, and a page cannot forget it.
 *
 * The answer differs by plane and {@see ConsoleScope} owns that: an environment
 * administrator holds the environment and there is no lesser role on that plane, so
 * reaching the console at all is the authorization; on the organization plane it is the
 * membership role.
 */
final class RequireConsoleAdmin
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app(ConsoleScope::class)->assertMayAdminister();

        return $next($request);
    }
}
