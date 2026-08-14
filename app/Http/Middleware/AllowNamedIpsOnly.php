<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The metrics endpoint, refused unless an address was named.
 *
 * `spatie/laravel-prometheus`'s own `AllowIps` returns `$next($request)` when the list is
 * empty — its config comment says so in as many words: "All IP's are allowed when empty."
 * So an operator who turns metrics on without thinking about who may read them gets an
 * open door.
 *
 * `config/prometheus.php` has claimed since it was written that we do not inherit that
 * reading, and we did: the file said "with the feature enabled and no addresses listed,
 * `AllowIps` refuses every request" while listing the vendor's `AllowIps`, which does the
 * opposite. The test beside it asserted the config file's literals back at itself and
 * could not have noticed. This class is the sentence made true.
 *
 * THE FAILURE DIRECTION IS DELIBERATE. An empty list costs a scrape; the other reading
 * costs a disclosure, and the thing being disclosed grows the day anybody registers a
 * collector — by which time nobody remembers the door was open.
 *
 * 404, not 403: whether this deployment serves metrics at all is not something to
 * confirm to somebody who was not invited to read them.
 */
class AllowNamedIpsOnly
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $allowed */
        $allowed = array_values(array_filter(
            (array) config('prometheus.allowed_ips', []),
            static fn (mixed $ip): bool => is_string($ip) && trim($ip) !== '',
        ));

        if ($allowed === []) {
            abort(404);
        }

        $ip = $request->ip();

        if (! is_string($ip) || ! IpUtils::checkIp($ip, $allowed)) {
            abort(404);
        }

        return $next($request);
    }
}
