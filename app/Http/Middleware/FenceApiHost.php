<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Api\ApiHost;
use App\Platform\Api\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE API HOST SERVES THE API AND NOTHING ELSE.
 *
 * The application's other routes are bound to no host, so without this `api.cboxid.com/login`
 * would render a sign-in page and start a session on the API's origin. Global and early:
 * a refusal here sets no cookie and reaches no route.
 */
final class FenceApiHost
{
    /**
     * What the API host answers: the API, its contract and reference, its RFC 9728 document —
     * and, for the transition, the old `/api/v1` paths, so a client released before the move
     * that is pointed at the new host still finds what it calls (the credential names the
     * environment there too, and the call is marked and counted like any old one).
     */
    private const array PATHS = ['v1', 'v1/*', 'openapi.json', 'docs', 'problems/*', '.well-known/oauth-protected-resource', 'up', 'api/v1', 'api/v1/*'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! ApiHost::serves($request) || $request->is(...self::PATHS)) {
            return $next($request);
        }

        return Problem::response('not_found', 'The API host serves the management API at /v1, its contract at /openapi.json and its reference at /docs.', 404);
    }
}
