<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE LOGIN HOST TAKES A WRITE FROM ITS OWN PAGES ONLY.
 *
 * The app host and the login host are two origins of ONE site, so `SameSite=Lax` sends the
 * SSO cookie on a form the console posts to the login host. The CSRF token still stands in
 * the way; this refuses the request before it is consulted, on what the browser itself
 * reports: `Sec-Fetch-Site`, and `Origin` where a browser sends no fetch metadata.
 *
 * A request carrying neither header is not a browser a page can script, and is left to the
 * CSRF token as before.
 *
 * {@see CROSS_SITE_BY_PROTOCOL} lists the writes a protocol requires to arrive from
 * somewhere else. Each authenticates itself and none acts on the session alone.
 */
final class RefuseCrossOriginWrites
{
    /**
     * @var list<string>
     */
    public const array CROSS_SITE_BY_PROTOCOL = [
        // The embedded sign-in endpoints: a publishable key and an origin allow-list.
        'frontend/v1/*',
        // SAML bindings, inbound and outbound: the XML signature.
        'sso/saml/idp/sso', 'sso/saml/*/acs', 'sso/saml/idp/slo', 'sso/saml/*/slo',
        // An OpenID Connect provider answering with `response_mode=form_post`: `state`.
        'sso/oidc/*/callback',
        // OpenID Connect and OAuth endpoints a relying party posts to.
        'oauth/authorize', 'oauth/logout', 'oauth/register', 'oauth/register/*',
    ];

    public function __construct(private readonly Surfaces $surfaces) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->surfaces->current($request) !== Surface::Login
            || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            || $request->is(...self::CROSS_SITE_BY_PROTOCOL)) {
            return $next($request);
        }

        abort_unless($this->sameOrigin($request), 403, 'This request did not come from the sign-in pages.');

        return $next($request);
    }

    private function sameOrigin(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        if ($site !== null) {
            // `none` is a request no page made: an address typed, a bookmark.
            return in_array(mb_strtolower($site), ['same-origin', 'none'], true);
        }

        $origin = $request->headers->get('Origin');

        return $origin === null
            || mb_strtolower(rtrim($origin, '/')) === mb_strtolower($request->getSchemeAndHttpHost());
    }
}
