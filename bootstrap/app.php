<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateAccountApi;
use App\Http\Middleware\AuthenticateEnvironmentAdmin;
use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Http\Middleware\EnforcePlane;
use App\Http\Middleware\PortalSession;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireScope;
use App\Http\Middleware\RequireSudo;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetEnvironment;
use App\Providers\ConsoleServiceProvider;
use App\Providers\PlatformServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        PlatformServiceProvider::class,
        ConsoleServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
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

        // The SAML HTTP-POST binding delivers the SP's AuthnRequest as a cross-site
        // form POST from the SP's own origin — it carries no Laravel CSRF token, so
        // with CSRF enabled the POST is rejected (419) before it reaches the IdP.
        // Exempt just that one endpoint (fail-closed: forgetting it breaks the POST
        // binding, it does not weaken security — the request is authenticated by its
        // XML signature and every assertion is pinned to the SP's registered ACS).
        $middleware->validateCsrfTokens(except: [
            'sso/saml/idp/sso',
            // The INBOUND ACS is the mirror case: the customer's IdP cross-site
            // POSTs the signed SAMLResponse here, carrying no Laravel CSRF token.
            // Same fail-closed reasoning — the XML signature is the authentication,
            // and the assertion is validated before any identity is read.
            'sso/saml/*/acs',
        ]);

        // The sidebar pin state is a pure UI preference written by JS
        // (document.cookie) and read server-side to render the correct rail width
        // on the first paint — so it never animates 52↔210px on a navigation. It
        // holds no sensitive data and MUST stay unencrypted (JS can't write a
        // Laravel-encrypted cookie).
        $middleware->encryptCookies(except: [
            'cbox-nav-pinned',
        ]);

        // Global so security headers cover API/JSON + error responses too, not
        // just the web group.
        $middleware->append(SecurityHeaders::class);

        // Pin the current environment (session-selected) for the console + hosted UI.
        $middleware->appendToGroup('web', SetEnvironment::class);

        $middleware->alias([
            'platform.auth' => Authenticate::class,
            'platform.guest' => RedirectIfAuthenticated::class,
            'portal.session' => PortalSession::class,
            'sudo' => RequireSudo::class,
            'scope' => RequireScope::class,
            'account.api' => AuthenticateAccountApi::class,
            'env.api' => AuthenticateEnvironmentApi::class,
            // Host-plane bulkheads + the environment-admin (account-layer) console gate.
            'plane' => EnforcePlane::class,
            'env.admin' => AuthenticateEnvironmentAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
