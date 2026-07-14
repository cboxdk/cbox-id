<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireSudo;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetEnvironment;
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
        // Set TRUSTED_PROXIES=* for a k8s deployment where only the ingress can
        // reach the pod, or a CIDR list for a known proxy range. (The base image's
        // nginx already forwards X-Forwarded-Proto → sets HTTPS on, so the request
        // scheme is correct without extra wiring.)
        //
        // Default is UNSET (trust none): if the app is ever exposed directly, an
        // attacker must not be able to spoof X-Forwarded-For to forge the client
        // IP. Opt into proxy trust explicitly per deployment.
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));
        $middleware->trustProxies(
            // '' → trust no proxies. Bare '*' is Laravel's trust-all special case;
            // a CIDR list is passed as an array (explode would break the '*' case).
            at: $trustedProxies === '' ? [] : ($trustedProxies === '*' ? '*' : explode(',', $trustedProxies)),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Global so security headers cover API/JSON + error responses too, not
        // just the web group.
        $middleware->append(SecurityHeaders::class);

        // Pin the current environment (session-selected) for the console + hosted UI.
        $middleware->appendToGroup('web', SetEnvironment::class);

        $middleware->alias([
            'platform.auth' => Authenticate::class,
            'platform.guest' => RedirectIfAuthenticated::class,
            'sudo' => RequireSudo::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
