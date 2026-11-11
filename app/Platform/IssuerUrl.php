<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Platform\PlatformRoot;

/**
 * A URL on the CURRENT ENVIRONMENT's own host — its issuer — whatever host is serving the
 * request.
 *
 * The environment console is served on the app host, so `url('/scim/v2')` there names the
 * app host, which serves no SCIM, no SAML and no sign-in for a customer's environment. A
 * value a console page hands an administrator to paste into somebody else's system (a SCIM
 * base URL, a SAML entity id, a sign-in link) has to name the environment's host instead,
 * and the issuer is that host: its verified custom domain, or `{slug}.{base domain}`.
 *
 * On the environment's own host (the API, the hosted pages) this is the same URL `url()`
 * would build — and for the platform root's own environment, whose console and sign-in
 * share the root host, it is `url()`.
 */
final class IssuerUrl
{
    public static function to(string $path = ''): string
    {
        // The platform root's own environment is served on the host its console is on.
        if (self::onPlatformRoot()) {
            return url($path === '' ? '/' : '/'.ltrim($path, '/'));
        }

        $issuer = rtrim(app(IssuerResolver::class)->issuer(), '/');

        return $path === '' ? $issuer : $issuer.'/'.ltrim($path, '/');
    }

    private static function onPlatformRoot(): bool
    {
        $root = app(PlatformRoot::class)->environment()?->environmentKey();

        return $root !== null && $root === app(EnvironmentContext::class)->current()?->environmentKey();
    }

    /** The host part of {@see to()}. */
    public static function host(): string
    {
        $host = parse_url(self::to(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : request()->getHost();
    }
}
