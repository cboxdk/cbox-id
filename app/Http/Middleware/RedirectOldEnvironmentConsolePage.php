<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\EnvironmentConsoleRedirectController;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An operator page's address on an environment's own host is an OLD environment-console
 * address there, and keeps answering as one.
 *
 * `/admin/organizations`, `/admin/search` and `/admin/usage` were environment-console pages
 * on every environment host before the console moved to the app host. Platform admin now
 * owns those paths, and a Laravel route can match a URI only once, so without this a
 * customer administrator's bookmark would 404 on their host instead of landing on the
 * console's new address. On the operator plane this does nothing: the request goes on to
 * `plane:operator` and the operator gate.
 */
final class RedirectOldEnvironmentConsolePage
{
    public function __construct(
        private readonly PlaneResolver $planes,
        private readonly EnvironmentContext $environments,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if ($request->isMethod('GET')
            && $this->planes->isMultiTenant()
            && ! $this->planes->onOperatorPlane($host)
            && $this->planes->servesEnvironmentAdmin($host)) {
            $path = trim(substr('/'.ltrim($request->path(), '/'), strlen('/admin')), '/');

            return app(EnvironmentConsoleRedirectController::class)($request, $this->environments, $path === '' ? null : $path);
        }

        return $next($request);
    }
}
