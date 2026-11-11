<?php

declare(strict_types=1);

namespace App\Platform\Surfaces;

use App\Platform\EnvironmentOrigin;
use App\Platform\MailLinks;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Illuminate\Routing\Route;
use Illuminate\Routing\UrlGenerator;

/**
 * IN LOGIN-HOST MODE, A NAMED ROUTE'S URL CARRIES THE HOST THAT SERVES IT.
 *
 * `route()` builds its origin from the request's host, or from `app.url` where there is no
 * request (a queue worker, a scheduled command). With one host that is right. With the
 * split it is a dead link in half the cases: `route('account')` drawn on a console page
 * names the app host, where the page is absent; `route('dashboard')` after a sign-up names
 * the login host; a reset link minted by a console action or a worker names whichever
 * host happened to be asking.
 *
 * So the host follows the ROUTE, in one place: a path the login host serves
 * ({@see Surfaces::ISSUER}, {@see Surfaces::ACCOUNT}) gets the login host, a console path
 * the app host, and a path every surface serves stays where it was asked for. Every
 * `route()`, `to_route()`, `redirect()->route()` and signed route goes through here, mailed
 * links ({@see MailLinks}) included, so no call site decides it again.
 *
 * WHAT IS LEFT ALONE:
 *  - single-host mode, entirely;
 *  - a URL asked for on a tenant's own host, which serves its own sign-in and account pages;
 *  - a URL minted while an environment's action runs ({@see EnvironmentOrigin}) or while a
 *    console page or a job acts in a tenant's environment: those links belong on that
 *    environment's host, and the code that mints them already says which;
 *  - `url()` and relative route paths, which name no route.
 */
final class SurfaceUrls
{
    /**
     * The default environment's key, once known. Asked on every URL a page builds, and a
     * page builds hundreds, so it is looked up once per request (the binding is scoped).
     */
    private ?string $defaultEnvironment = null;

    public function __construct(private readonly Surfaces $surfaces) {}

    /** Install the rule on the URL generator. Safe to call once per application. */
    public static function register(UrlGenerator $url): void
    {
        $url->formatHostUsing(static fn (string $root, ?Route $route = null): string => app(self::class)->root($root, $route));
    }

    /** The origin a URL for $route is built on, given the one the generator chose. */
    public function root(string $root, ?Route $route): string
    {
        if ($route === null || $route->getDomain() !== null || ! $this->surfaces->split()) {
            return $root;
        }

        if (EnvironmentOrigin::pinned() !== null || ! $this->inDefaultEnvironment()) {
            return $root;
        }

        $host = parse_url($root, PHP_URL_HOST);

        if (is_string($host) && $this->surfaces->of($host) === Surface::Tenant) {
            return $root;
        }

        $origin = match ($this->surfaces->kindOfPath($route->uri())) {
            PathKind::Issuer, PathKind::Account => $this->surfaces->loginOrigin(),
            PathKind::Console => $this->surfaces->consoleOrigin(),
            PathKind::Shared, PathKind::Infrastructure => null,
        };

        return $origin ?? $root;
    }

    /** {@see Surfaces::inDefaultEnvironment()}, with the default environment remembered. */
    private function inDefaultEnvironment(): bool
    {
        $current = app(EnvironmentContext::class)->current()?->environmentKey();

        if ($current === null) {
            return true;
        }

        return $current === ($this->defaultEnvironment ??= $this->surfaces->defaultEnvironmentKey());
    }
}
