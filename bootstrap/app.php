<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\SecurityHeaders;
use App\Providers\PlatformServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        PlatformServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a TLS-terminating reverse proxy (Traefik on k8s, Cloudflare,
        // etc.), trust the forwarded headers so the audit trail records the real
        // client IP, rate limiting keys on it, and issuer/cookie host are right.
        // TRUSTED_PROXIES=* is safe here because only the ingress can reach the
        // pod. (The base image's nginx already forwards X-Forwarded-Proto → sets
        // HTTPS on, so the request scheme is correct without extra wiring.)
        $trustedProxies = (string) env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(
            // Bare '*' is Laravel's trust-all special case; a CIDR list is passed
            // as an array. explode(',', '*') would break the special case.
            at: $trustedProxies === '*' ? '*' : explode(',', $trustedProxies),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Global so security headers cover API/JSON + error responses too, not
        // just the web group.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'platform.auth' => Authenticate::class,
            'platform.guest' => RedirectIfAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
