<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Surfaces\Surfaces;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IN LOGIN-HOST MODE, EACH HOST SERVES ITS OWN SURFACE AND NOTHING ELSE.
 *
 * The application's routes are bound to no host, so without this the app host would answer
 * `/oauth/authorize` and discovery for the default environment, the login host would
 * render the console, and any name pointed at the deployment would do both: an unmapped
 * host resolves to the default environment.
 *
 * Global and ahead of routing, like {@see FenceApiHost}: a refusal here starts no session,
 * sets no cookie and reaches no route. The answer is a 404, because the surface is absent
 * on that host and not merely forbidden. Which path belongs where is {@see Surfaces}.
 *
 * With no login host configured this passes every request through untouched.
 */
final class FenceSurfaces
{
    public function __construct(private readonly Surfaces $surfaces) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->surfaces->admits($request), 404);

        return $next($request);
    }
}
