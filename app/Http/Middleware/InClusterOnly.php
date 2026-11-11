<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Api\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/internal/v1/*` IS FOR CALLERS INSIDE THE CLUSTER: probes, the metrics scrape, Engage's
 * eligibility check over `cbox-id.cbox-id.svc.cluster.local`.
 *
 * The tunnel refuses `/internal/` from the internet (the infra change that ships with the
 * contract step). Until then, and behind it as well, a request that came through Cloudflare
 * says so — it carries the headers Cloudflare adds to every request it forwards — and in
 * production such a request is answered as if the route did not exist.
 */
final class InClusterOnly
{
    /** Headers Cloudflare sets on every request it proxies; an in-cluster call has none. */
    private const array EDGE_HEADERS = ['CF-Connecting-IP', 'CF-Ray', 'CDN-Loop'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && self::fromTheEdge($request)) {
            return Problem::response('not_found', 'No such resource.', 404);
        }

        return $next($request);
    }

    public static function fromTheEdge(Request $request): bool
    {
        foreach (self::EDGE_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }
}
