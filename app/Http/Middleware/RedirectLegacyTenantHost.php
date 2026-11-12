<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Environments\TenantBaseDomain;
use App\Platform\IntendedUrl;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Organization\Models\Environment;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AN ENVIRONMENT'S OLD ADDRESS SENDS THE BROWSER TO ITS NEW ONE.
 *
 * When the tenant base domain moves ({@see TenantBaseDomain}), `{slug}.{old base}` keeps
 * resolving. The framework already redirects the METADATA there (`CanonicalIssuerHost`)
 * and deliberately nothing else, so the old name went on serving the sign-in page, the
 * profile and a session cookie: two origins for one environment, one of them on the
 * domain the move was meant to leave. This sends a person's NAVIGATION to the canonical
 * host (the verified custom domain, else `{slug}.{first base}`).
 *
 * Only what can follow a redirect, and only when nothing on the old host is in flight:
 *
 *  - safe methods and document navigations only. A form POST cannot be redirected (CSP
 *    `form-action` covers the whole chain) and an HTTP client drops `Authorization`
 *    across origins, so those are served where they arrived, as before;
 *  - never a signed link: its signature covers the host it was written for;
 *  - never `/sso/*`. A SAML message names the endpoint it was sent to (`Destination`) and
 *    a service provider that has not re-imported metadata still addresses the old host;
 *  - and not while this browser is on its way back to such an endpoint: the sign-in that
 *    a SAML request started keeps its session on the old host, so the pages of that
 *    sign-in are served there too.
 *
 * 302 + no-store, for the reason `CanonicalIssuerHost` gives: which host is canonical can
 * change again, and a cached permanent redirect could not be taken back.
 *
 * Inert with fewer than two base domains, and `cbox-id.tenancy.redirect_legacy_base_domains`
 * turns it off.
 */
final class RedirectLegacyTenantHost
{
    public function __construct(
        private readonly EnvironmentContext $environments,
        private readonly IssuerResolver $issuers,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('cbox-id.tenancy.redirect_legacy_base_domains', true) !== true) {
            return $next($request);
        }

        $slug = TenantBaseDomain::legacySlugOf($request->getHost());

        if ($slug === null || ! $this->isNavigation($request) || $this->midFlight($request)) {
            return $next($request);
        }

        // The host has to be THIS environment's old name. An unmapped name resolves to the
        // platform root, and the platform's own hosts (app, api, login) are not environments.
        $key = $this->environments->current()?->environmentKey();
        $environment = $key === null ? null : Environment::query()->find($key);

        if ($environment === null || $environment->is_default || mb_strtolower($environment->slug) !== $slug) {
            return $next($request);
        }

        $canonical = $this->issuers->canonicalHost();

        if ($canonical === null || strcasecmp($request->getHost(), $canonical) === 0) {
            return $next($request);
        }

        return new RedirectResponse(
            $this->issuers->issuer().$request->getRequestUri(),
            302,
            ['Cache-Control' => 'no-store'],
        );
    }

    private function isNavigation(Request $request): bool
    {
        if (! $request->isMethodSafe() || $request->headers->has('Authorization') || $request->headers->has('X-Inertia')) {
            return false;
        }

        if ($request->query->has('signature') || $request->is('sso/*', '.well-known/*')) {
            return false;
        }

        // A browser says what kind of request this is. Without the header (an old browser,
        // curl) the answer falls back to what the caller asked for.
        $mode = $request->headers->get('Sec-Fetch-Mode');

        return $mode === null ? ! $request->expectsJson() : $mode === 'navigate';
    }

    private function midFlight(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $intended = $request->session()->get(IntendedUrl::KEY);
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;

        return is_string($path) && str_starts_with($path, '/sso/');
    }
}
