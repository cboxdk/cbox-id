<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Console\OperatorPaths;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * A GET of a platform-admin write (`/admin/organizations/{id}/status`) is the wrong method,
 * not an old environment-console page.
 *
 * Put in front of the old-console fallback under `/admin`, which would otherwise catch the
 * GET (a fallback matches before Laravel's own 405) and redirect it to a page that is not
 * there. A suspend reachable by GET is one a prefetch or a pasted link can perform, so the
 * answer stays a plain 405 on every host.
 */
final class RefuseReadOfOperatorWrite
{
    public function __construct(private readonly Router $router) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (OperatorPaths::owns('/'.$request->path()) && $this->isWrite($request)) {
            throw new MethodNotAllowedHttpException(['POST']);
        }

        return $next($request);
    }

    private function isWrite(Request $request): bool
    {
        try {
            return ! $this->router->getRoutes()->match(Request::create($request->getUri(), 'POST'))->isFallback;
        } catch (HttpExceptionInterface) {
            return false;
        }
    }
}
