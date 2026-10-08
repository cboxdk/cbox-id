<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Controllers\Api\ActionController;
use App\Platform\Actions\Principal\EnvironmentMemberPrincipal;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Closure;
use Illuminate\Routing\UrlGenerator;

/**
 * Run something with every URL it mints on ONE ENVIRONMENT'S OWN HOST.
 *
 * WHY THIS EXISTS. `route()` and `url()` build their origin from the request's host. For a
 * request served on the environment's own host that is the right answer, and for one served
 * on the PLATFORM ROOT that acts in an environment — a workspace member's root token with
 * `Cbox-Environment` over REST, or the `environment` argument of a root `/mcp` tool
 * ({@see EnvironmentMemberPrincipal::within()}) — it is a dead link. The root is
 * not an identity provider: it serves no SAML, no Admin Portal and no hosted sign-in, so
 * the service-provider URLs an IT admin pastes into Okta, the Admin Portal link mailed to
 * them and an invitation's accept link all 404 there. Found live: `cbox id sso connections
 * create` signed in at the root answered `https://<root>/sso/saml/{id}/acs`, and the portal
 * link it created opened "This link has expired" on the root while the same token worked on
 * the environment's host.
 *
 * The origin is the environment's ISSUER — its verified custom domain, else its
 * `{slug}.{base_domain}` — which is by definition the host that serves it.
 *
 * WHAT STAYS ON THE REQUEST'S OWN HOST is the caller's business, not this: an approval's
 * `poll_url` is where the SAME credential polls, and the root's token is refused on the
 * environment's host ({@see ActionController}).
 *
 * A MAILED LINK FOLLOWS THE PIN. {@see MailLinks} forces `app.url` on a host that resolves
 * to no environment — the platform root is one — and would otherwise mail an invitation for
 * the environment's organization on the root (found live too: the accept link redirected
 * to the root's sign-in). It asks {@see self::pinned()} first; the pinned origin is the
 * environment's issuer, derived from the deployment and never from the Host header, so it
 * is exactly the kind of origin a mailed link may carry.
 *
 * Put back in `finally` to whatever was pinned before (normally nothing, the only state
 * this application leaves the generator in between requests).
 */
final class EnvironmentOrigin
{
    /**
     * The origin URLs are pinned to right now, if any. Process state rather than a scoped
     * binding because {@see MailLinks} must see it from wherever it is resolved, and it is
     * restored in `finally` so neither a throw nor the next request (or Octane worker
     * reuse) inherits it.
     */
    private static ?string $pinned = null;

    public function __construct(
        private readonly IssuerResolver $issuers,
        private readonly UrlGenerator $url,
    ) {}

    /** The environment origin URLs are being minted on, or null when nothing pins one. */
    public static function pinned(): ?string
    {
        return self::$pinned;
    }

    /**
     * Point the generator at $origin (or back at the request, for null).
     */
    public static function apply(UrlGenerator $url, ?string $origin): void
    {
        $scheme = $origin !== null ? parse_url($origin, PHP_URL_SCHEME) : null;

        $url->forceRootUrl($origin);
        $url->forceScheme(is_string($scheme) ? $scheme : null);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(string $environmentId, Closure $callback): mixed
    {
        $origin = rtrim($this->issuers->forEnvironment($environmentId), '/');
        $scheme = parse_url($origin, PHP_URL_SCHEME);

        if (! is_string($scheme) || parse_url($origin, PHP_URL_HOST) === null) {
            return $callback();
        }

        $previous = self::$pinned;
        self::$pinned = $origin;
        self::apply($this->url, $origin);

        try {
            return $callback();
        } finally {
            self::$pinned = $previous;
            self::apply($this->url, $previous);
        }
    }
}
