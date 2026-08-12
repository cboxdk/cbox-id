<?php

use App\Http\ApiErrorRenderer;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateEnvironmentAdmin;
use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Http\Middleware\AuthenticateOrganizationApi;
use App\Http\Middleware\EnforcePlane;
use App\Http\Middleware\PointAtFirstRun;
use App\Http\Middleware\PortalSession;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireEnvironmentSudo;
use App\Http\Middleware\RequireMultiTenant;
use App\Http\Middleware\RequireScope;
use App\Http\Middleware\RequireSudo;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetEnvironment;
use App\Http\Middleware\TrustHostsExceptHealth;
use App\Platform\TrustedHosts;
use App\Providers\ConsoleServiceProvider;
use App\Providers\PlatformServiceProvider;
use Cbox\Id\Analytics\AnalyticsServiceProvider;
use Cbox\Id\Billing\BillingServiceProvider;
use Cbox\Id\Compliance\ComplianceServiceProvider;
use Cbox\Id\Connectors\ConnectorsServiceProvider;
use Cbox\Id\Devices\DevicesServiceProvider;
use Cbox\Id\Kernel\Crypto\Exceptions\CryptoConfigurationException;
use Cbox\Id\RiskPlus\RiskPlusServiceProvider;
use Cbox\Id\Whitelabel\WhitelabelServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        PlatformServiceProvider::class,
        ConsoleServiceProvider::class,

        // The console modules under modules/. They were separate Composer packages
        // and reached the app through Laravel's package auto-discovery; vendored
        // in-repo there is no installed package to discover, so they are named here.
        // Each still registers its own nav, routes, views, migrations and feature
        // gate through the same console-kit sockets a third-party plugin would use.
        AnalyticsServiceProvider::class,
        BillingServiceProvider::class,
        ComplianceServiceProvider::class,
        ConnectorsServiceProvider::class,
        DevicesServiceProvider::class,
        RiskPlusServiceProvider::class,
        WhitelabelServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // No `health:` entry on purpose. Laravel's built-in health route renders an
        // HTML status page at /up, which SHADOWED the framework package's documented
        // JSON liveness probe (`{"status":"ok"}`) that deployments and the DAST
        // pipeline consume. Worse, that page pulls Tailwind from a CDN and fonts from
        // bunny.net — both refused by this app's own CSP (`script-src 'self'`,
        // `font-src 'self' data:`), so every probe rendered unstyled and emitted CSP
        // violation noise. The package route is the one we want; leave it unshadowed.
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

        // Refuse a Host header this deployment does not answer on, before anything reads
        // it. Nothing registered this, and `SetEnvironment` answers an unmapped host with
        // the platform root rather than a 404 (deliberately — see that class), so every
        // account-plane surface rendered under any name pointed at us: `Host: evil.example`
        // on the workspace forgot-password page mailed a working reset link on the
        // attacker's domain, because `route()` builds its origin from the Host.
        //
        // Derived per request from what the deployment already states — account host,
        // `base_domains`, tenant custom domains ({@see TrustedHosts}) — never a hardcoded
        // list. `subdomains: true` adds `app.url` and everything under it, which is what
        // keeps a single-tenant install (no base domains, no account host) from fencing
        // itself out: the derivation returning nothing is a safe answer there.
        $middleware->trustHosts(at: static fn (): array => app(TrustedHosts::class)->patterns());

        // …with the health endpoints exempt. A kubelet probes the POD IP, and every host
        // derived above is a public name, so TrustHosts — which runs first, before routing
        // — would 400 the liveness probe and crash-loop every pod. See the middleware: the
        // promise `docs/operations/deployment.md` makes is about the PATH, and enumerating
        // the shapes an internal caller arrives as (IPv6, link-local, a bare Service name)
        // is an enumeration that is wrong the moment a cluster changes.
        $middleware->replace(TrustHosts::class, TrustHostsExceptHealth::class);

        // The SAML HTTP-POST binding delivers the SP's AuthnRequest as a cross-site
        // form POST from the SP's own origin — it carries no Laravel CSRF token, so
        // with CSRF enabled the POST is rejected (419) before it reaches the IdP.
        // Exempt just that one endpoint (fail-closed: forgetting it breaks the POST
        // binding, it does not weaken security — the request is authenticated by its
        // XML signature and every assertion is pinned to the SP's registered ACS).
        $middleware->validateCsrfTokens(except: [
            // The embedded sign-in endpoint. It is a deliberate cross-origin POST from a
            // page on somebody else's site, so it carries no Laravel CSRF token and never
            // could — and CSRF is the wrong control for it anyway: the attack CSRF
            // prevents is a request made with a victim's AMBIENT session, and this request
            // authenticates itself by the credentials in its body. What decides whether a
            // caller may make it at all is the publishable key plus the origin allow-list,
            // which is a check no forged cross-site form can pass.
            //
            // Found by calling it in production, where it answered 419 while every test
            // was green — Laravel's test helpers bypass CSRF, so the suite could not see
            // it. BrowserlessCsrfTest exists so it cannot happen again.
            'frontend/v1/*',
            'sso/saml/idp/sso',
            // The INBOUND ACS is the mirror case: the customer's IdP cross-site
            // POSTs the signed SAMLResponse here, carrying no Laravel CSRF token.
            // Same fail-closed reasoning — the XML signature is the authentication,
            // and the assertion is validated before any identity is read.
            'sso/saml/*/acs',

            // Single Logout, both roles. The IdP metadata this platform PUBLISHES
            // advertises the HTTP-POST binding for SLO (which is what Okta and ADFS
            // prefer), so a conformant SP picks it — and every logout was answered
            // with Laravel's 419 session-expired HTML instead of a LogoutResponse,
            // leaving the IdP session live while the SP reported a transport failure.
            // The controller even carries a POST branch written for a path the
            // middleware made unreachable. Authenticated by XML signature, exactly as
            // the two exemptions above are.
            'sso/saml/idp/slo',
            'sso/saml/*/slo',

            // OIDC RP-Initiated Logout §5 makes POST mandatory at the end-session
            // endpoint, and POST is the binding a client MUST use when the
            // id_token_hint is too long for a URL. The controller reads its parameters
            // with input() precisely so the form binding works. Nothing is minted here;
            // the open-redirect guard is the registered post_logout_redirect_uri
            // allow-list, compared exactly.
            'oauth/logout',

            // RFC 7591/7592 dynamic client registration is a back-channel JSON API —
            // no conformant client sends a Laravel CSRF token. Latent today because
            // registration is disabled by default, but the day it is switched on,
            // discovery starts advertising an endpoint that answers 419 HTML. The
            // credential here is the registration access token, not a session.
            'oauth/register',
            'oauth/register/*',

            // /authorize over POST, which OIDC Core §3.1.2.1 makes mandatory. The POST
            // comes cross-site from the relying party and carries no Laravel token; the
            // component validates client, redirect_uri, scope and PKCE from scratch.
            'oauth/authorize',
        ]);

        // The sidebar pin state is a pure UI preference written by JS
        // (document.cookie) and read server-side to render the correct rail width
        // on the first paint — so it never animates 52↔210px on a navigation. It
        // holds no sensitive data and MUST stay unencrypted (JS can't write a
        // Laravel-encrypted cookie).
        $middleware->encryptCookies(except: [
            'cbox-nav-pinned',
            // The theme, for exactly the reason above and with exactly the same failure
            // when it is not done. It lived in `localStorage`, which the server cannot
            // read, so the first paint used the OS preference and the deferred bundle
            // flipped it afterwards — and `wire:navigate` re-rendered `<html>` without it
            // and dropped the choice mid-walk. {@see \App\Platform\Theme}.
            'cbox-theme',
        ]);

        // Global so security headers cover API/JSON + error responses too, not
        // just the web group.
        $middleware->append(SecurityHeaders::class);

        // Pin the current environment (session-selected) for the console + hosted UI.
        $middleware->appendToGroup('web', SetEnvironment::class);

        // An UNINSTALLED deployment has exactly one surface: the first-run screen. In
        // the web group rather than on individual routes because the property is about
        // the deployment, not about any one page — a fresh box must never answer with a
        // sign-in form no credential can satisfy, and the screen that provisions the
        // platform root must stop existing the moment it has. See the middleware.
        $middleware->appendToGroup('web', PointAtFirstRun::class);

        $middleware->alias([
            'platform.auth' => Authenticate::class,
            'platform.guest' => RedirectIfAuthenticated::class,
            'portal.session' => PortalSession::class,
            'sudo' => RequireSudo::class,
            // The environment plane's own step-up. A separate alias AND a separate
            // session key: a confirmation on one plane must never satisfy the other,
            // and this plane's administrator acts on every organization in the
            // environment.
            'env.sudo' => RequireEnvironmentSudo::class,
            'scope' => RequireScope::class,
            'organization.api' => AuthenticateOrganizationApi::class,
            'env.api' => AuthenticateEnvironmentApi::class,
            // Host-plane bulkheads + the environment-admin (account-layer) console gate.
            'plane' => EnforcePlane::class,
            'env.admin' => AuthenticateEnvironmentAdmin::class,
            // A surface that only exists in the multi-tenant shape (see the class).
            'multi.tenant' => RequireMultiTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One error shape on the REST API, actually honoured. Both OpenAPI specs
        // promise `{error, message}` on every failure and account.yaml marks both
        // REQUIRED — but an empty withExceptions() meant `$request->validate()`
        // rendered Laravel's default `{message, errors}` with no `error` key, and a
        // throttled request rendered a bare `{"message":"Too Many Attempts."}`. Those
        // are the two most common failures a generated client meets.
        $exceptions->render(ApiErrorRenderer::render(...));

        // A DEPLOYMENT THAT WAS NEVER CONFIGURED SHOULD SAY SO, not 500.
        //
        // `SecretBox` refuses to boot without a master key, which is correct — a platform
        // that sealed secrets under a key it invented at boot would lose them all on the
        // next restart. But the resulting exception reached the browser as a blank 500 on
        // EVERY page, including `/first-run`, the one screen that exists for a container
        // somebody else started and cannot run a CLI inside.
        //
        // So the failure keeps its status and gains the one sentence that resolves it.
        // Measured on a clean checkout: `cp .env.example .env && php artisan migrate` then
        // any URL — the operator saw a stack trace with `debug` on, and nothing at all with
        // it off.
        $exceptions->render(function (CryptoConfigurationException $e, Request $request): ?Response {
            if ($request->expectsJson()) {
                return null;
            }

            return response()->view('errors.unconfigured', [
                'reason' => $e->getMessage(),
            ], 503);
        });
    })->create();
