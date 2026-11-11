<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Api\ApiHost;
use Cbox\Id\Api\Http\Middleware\ResolveEnvironment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The framework's host-based environment resolution everywhere but the API host, where the
 * host names nobody and the credential decides ({@see ResolveApiEnvironment}).
 *
 * Bound in place of {@see ResolveEnvironment}, so every route that already names it — the
 * old `/api/v1` table reached through the API host's transition path included — resolves
 * the right environment there without a second copy of its route list.
 */
final class HostAwareResolveEnvironment extends ResolveEnvironment
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiHost::serves($request)) {
            return app(ResolveApiEnvironment::class)->handle($request, $next);
        }

        return parent::handle($request, $next);
    }
}
