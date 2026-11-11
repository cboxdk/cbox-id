<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Api\ApiUrls;
use App\Platform\Api\LegacyApiUsage;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * EVERY `/api/v1` RESPONSE SAYS IT IS DEPRECATED, AND IS COUNTED.
 *
 * `Deprecation` (RFC 9745) carries the date the paths were superseded, `Sunset` (RFC 8594) the
 * earliest they may go, and `Link` the replacement and its reference. The paths keep
 * answering exactly as before: nothing about the response changes but its headers. Removal
 * waits for seven days of silence on {@see LegacyApiUsage}.
 *
 * The Authenticator's `/api/v1/devices` and `/api/v1/approvals*` are marked too — installed
 * copies only update through the stores, so they are the last to be removed, but they are
 * deprecated all the same.
 */
final class DeprecateLegacyApi
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! self::isLegacy($request)) {
            return $response;
        }

        self::mark($response);

        try {
            LegacyApiUsage::record($request, $response);
        } catch (Throwable $e) {
            // Counting must never cost a caller its answer.
            report($e);
        }

        return $response;
    }

    public static function isLegacy(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }

    public static function mark(Response $response): void
    {
        $since = self::date('cbox-id.api_transition.deprecated_since', '2026-11-11');
        $sunset = self::date('cbox-id.api_transition.sunset', '2027-02-01');

        $response->headers->set('Deprecation', '@'.$since->getTimestamp());
        $response->headers->set('Sunset', $sunset->toRfc7231String());
        $response->headers->set('Link', '<'.ApiUrls::base().'>; rel="successor-version", <'.ApiUrls::docs().'>; rel="deprecation"; type="text/html"', false);
    }

    private static function date(string $key, string $fallback): CarbonImmutable
    {
        $value = config($key);

        try {
            return CarbonImmutable::parse(is_string($value) && $value !== '' ? $value : $fallback, 'UTC')->startOfDay();
        } catch (Throwable) {
            return CarbonImmutable::parse($fallback, 'UTC')->startOfDay();
        }
    }
}
