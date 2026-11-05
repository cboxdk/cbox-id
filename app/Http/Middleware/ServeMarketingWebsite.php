<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Serve the public website before session, tenant and console middleware run. */
final class ServeMarketingWebsite
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $host = config('marketing.host');
        $appUrl = config('marketing.app_url');

        if (! is_string($host) || $host === '' || ! is_string($appUrl)
            || ! in_array($request->getHost(), [$host, 'www.'.$host], true)
            || parse_url($appUrl, PHP_URL_HOST) === $host) {
            return $next($request);
        }

        if ($request->getHost() === 'www.'.$host) {
            return redirect()->away('https://'.$host.$request->getRequestUri(), 308);
        }

        if ($request->is('up', 'health/*')) {
            return $next($request);
        }

        if ($request->isMethodSafe() && $request->path() === '/') {
            return response()->view('marketing.home', ['appUrl' => rtrim($appUrl, '/')])
                ->header('Cache-Control', 'public, max-age=300');
        }

        if ($request->isMethodSafe() && $request->path() === 'robots.txt') {
            return response("User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /oauth/\nDisallow: /mcp\nSitemap: https://{$host}/sitemap.xml\n", 200,
                ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        if ($request->isMethodSafe() && $request->path() === 'sitemap.xml') {
            return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://'.e($host).'/</loc></url></urlset>', 200,
                ['Content-Type' => 'application/xml; charset=UTF-8']);
        }

        // A temporary, method-preserving redirect also handles old bookmarks and form
        // submissions. Never derive the destination origin from a request parameter.
        return redirect()->away(rtrim($appUrl, '/').$request->getRequestUri(), 307)
            ->header('Cache-Control', 'no-store');
    }
}
