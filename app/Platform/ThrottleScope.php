<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;

/**
 * The environment component of a rate-limit key.
 *
 * A multi-tenant deployment serves every tenant from one process and one cache, so a
 * throttle key built from only the things a request carries — an IP, an email address —
 * puts unrelated people in the same bucket. The same address exists independently in
 * every tenant here; that is what the product is. So:
 *
 *   - a login throttle keyed (action, email, ip) let failed attempts against one tenant's
 *     account lock out another tenant's account of the same name, which anyone could
 *     trigger deliberately by guessing at an address they knew existed elsewhere;
 *   - a signup throttle keyed on the IP alone let one office behind a single NAT address
 *     exhaust the budget for every tenant reachable from that office.
 *
 * Neither is a breach and both are a cross-tenant fault: one customer's traffic changing
 * another customer's service. Adding this to the key is the whole fix.
 *
 * A deployment-wide abuse throttle is a DIFFERENT key, deliberately — if one is ever
 * wanted, it should be written as its own limiter rather than by leaving this one broad.
 */
final class ThrottleScope
{
    /**
     * `unscoped` when no environment is resolved: a request that reached a throttle
     * without one is a shape that should be rare, and giving it a shared bucket is safer
     * than giving it none. It is not silently merged with any real environment's.
     */
    public static function key(): string
    {
        return app(EnvironmentContext::class)->current()?->environmentKey() ?? 'unscoped';
    }
}
