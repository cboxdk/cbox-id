<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;

/**
 * THE HOST AN ENROLMENT CODE NAMES: the one the app is to ask for
 * `/.well-known/cbox-authenticator` and then sign in at.
 *
 * It was the host the Trusted devices page was opened on, which is right while that host
 * is also the issuer. In login-host mode the page is a console page on the app host, and
 * the app host issues nothing: discovery is absent there, so a code naming it could never
 * be redeemed. There the code names the issuer's host instead, which is also the `iss`
 * the code is signed with ({@see EnrolmentToken}) and the only value the app accepts as
 * the discovery document's `issuer`.
 *
 * Everywhere else (single-host mode, a tenant's own host) it stays the request host.
 */
final class EnrolmentHost
{
    public static function name(): string
    {
        if (app(Surfaces::class)->is(Surface::Console)) {
            $host = parse_url(app(IssuerResolver::class)->issuer(), PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return mb_strtolower($host);
            }
        }

        return request()->getHost();
    }
}
