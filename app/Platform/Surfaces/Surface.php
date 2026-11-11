<?php

declare(strict_types=1);

namespace App\Platform\Surfaces;

use App\Http\Middleware\FenceApiHost;
use App\Http\Middleware\ServeMarketingWebsite;

/**
 * What a HOST is for, in login-host mode ({@see Surfaces}).
 *
 * One host, one surface, decided by an exact match on the host name and never by the
 * environment a request happened to resolve to.
 */
enum Surface: string
{
    /** The issuer of the default environment: hosted sign-in, `/oauth/*`, discovery, account security. */
    case Login = 'login';

    /** The app host: the console and the operator plane, a relying party of the login host. */
    case Console = 'console';

    /** The API host (`/v1`), fenced by {@see FenceApiHost}. */
    case Api = 'api';

    /** A customer environment's own host: the issuer of that environment. */
    case Tenant = 'tenant';

    /** The marketing website's host, answered by {@see ServeMarketingWebsite}. */
    case Marketing = 'marketing';

    /** A host that is none of the above. It serves the health probes and nothing else. */
    case Unmapped = 'unmapped';

    /** Whether an identity provider answers on this surface. */
    public function issues(): bool
    {
        return $this === self::Login || $this === self::Tenant;
    }
}
