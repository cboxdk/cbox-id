<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Api\ApiHost;
use App\Platform\Api\ApiUrls;
use App\Platform\Api\Problem;
use App\Platform\Surfaces\PathKind;
use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A NAME THE DEFAULT ENVIRONMENT USED TO BE ISSUED UNDER ONLY REDIRECTS.
 *
 * `cbox-id.hosts.legacy_login` lists such names ({@see Surface::Legacy}). After the issuer
 * moved to the login host, people still hold bookmarks and mailed links under the old
 * name, and programs are still configured with it. This answers them, in this order:
 *
 *  1. The health probes pass through, as on every host.
 *  2. What a program calls ({@see Surfaces::LEGACY_GONE}: `/oauth/*`, the APIs, SCIM, MCP,
 *     the frontend API), any request that carries an `Authorization` header and any
 *     method other than GET and HEAD on a page: 410 with an RFC 9457 problem that names
 *     the new issuer. Never a redirect: a 308 would have the client repeat its credential
 *     or its authorization code at a host it was not configured for, and a token issued
 *     there names an issuer the client does not expect.
 *  3. A page (the issuer's and a person's own pages, discovery and JWKS, and
 *     {@see Surfaces::LEGACY_PAGES}): GET and HEAD answer 308 to the same path and query
 *     on the host that serves it now, the login host or, for a console page, the app
 *     host. Discovery and JWKS are redirected on purpose: the document a client then
 *     reads names the login host as `issuer`, which a conforming client refuses until it
 *     is reconfigured.
 *  4. Everything else, `/admin` and the console first among it: 404. The old name resolves
 *     to the default environment by its slug, so serving anything here would put the
 *     operator plane on a second origin.
 *
 * Global and ahead of routing: no session starts, no cookie is read or set, no route runs.
 * A no-op in single-host mode and for every host that is not listed.
 */
final class RedirectLegacyLoginHost
{
    public function __construct(private readonly Surfaces $surfaces) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->surfaces->current($request) !== Surface::Legacy) {
            return $next($request);
        }

        $kind = $this->surfaces->kindOf($request);

        if ($kind === PathKind::Infrastructure) {
            return $next($request);
        }

        $page = $request->is(...Surfaces::LEGACY_PAGES);

        if (! $page && $request->is(...Surfaces::LEGACY_GONE)) {
            return $this->gone();
        }

        if (! $page && $kind === PathKind::Console) {
            abort(404);
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $this->gone();
        }

        if ($request->headers->has('Authorization')) {
            return $this->gone();
        }

        return new RedirectResponse($this->target($request, $kind), 308, ['Cache-Control' => 'no-store']);
    }

    /** The same path and query on the host that serves the page now. */
    private function target(Request $request, PathKind $kind): string
    {
        $path = '/'.ltrim($request->getPathInfo(), '/');
        $base = $kind === PathKind::Console ? $this->surfaces->consoleUrl($path) : $this->surfaces->loginUrl($path);
        $query = $request->server->get('QUERY_STRING');

        return $base.(is_string($query) && $query !== '' ? '?'.$query : '');
    }

    private function gone(): Response
    {
        $issuer = (string) $this->surfaces->loginOrigin();
        $detail = "This host no longer issues or serves an API. The issuer is {$issuer}: read {$issuer}/.well-known/openid-configuration and configure the client with that issuer.";

        // `message` beside `detail`: the old `/api/v1` envelope's readers look for it.
        $extra = ['message' => $detail, 'issuer' => $issuer];

        if (ApiHost::name() !== null) {
            $extra['api_base'] = ApiUrls::base();
        }

        return Problem::response('issuer_moved', $detail, 410, $extra, ['Cache-Control' => 'no-store']);
    }
}
