<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The `api-devices` throttle.
 *
 * The host's ApiRateLimiters covers its own four planes and lives in app/, which this
 * module does not edit — so the same shape is reimplemented here rather than reached
 * into. It deliberately reads the host's existing `api.ip_ceiling_multiplier` instead
 * of inventing a parallel knob: one operator dial for the abuse backstop across every
 * plane.
 *
 * Keying follows the host's reasoning exactly. `throttle` runs BEFORE `scope:`, so the
 * verified token is not on the request yet; the key is therefore a SHA-256 fingerprint
 * of the bearer AS PRESENTED. For this API that is naturally a per-USER bucket, since
 * every user's mobile access token is distinct. Keying on the client id would be the
 * mistake worth naming: the authenticator is ONE public OAuth client, so that would put
 * every mobile user on the platform into a single bucket.
 *
 * A fingerprint cannot tell a valid credential from a bogus one, so a much laxer per-IP
 * limit rides alongside — otherwise a flood of distinct junk tokens would mint itself a
 * fresh bucket per request.
 */
final class DeviceRateLimiter
{
    public static function register(): void
    {
        RateLimiter::for(
            'api-devices',
            /** @return list<Limit> */
            static fn (Request $request): array => self::limits($request),
        );
    }

    /**
     * @return list<Limit>
     */
    private static function limits(Request $request): array
    {
        $budget = max(1, DeviceConfig::int('id-devices.rate_limit', 60));
        $ip = (string) ($request->ip() ?? 'unknown');
        $credential = self::credential($request);

        $limits = [Limit::perMinute($budget)->by($credential ?? 'ip:'.$ip)];

        $multiplier = DeviceConfig::int('api.ip_ceiling_multiplier', 10);

        if ($multiplier > 0 && $credential !== null) {
            $limits[] = Limit::perMinute($budget * $multiplier)->by('ip:'.$ip);
        }

        return $limits;
    }

    private static function credential(Request $request): ?string
    {
        $bearer = $request->bearerToken();

        if (! is_string($bearer) || $bearer === '') {
            return null;
        }

        return 'cred:'.substr(hash('sha256', $bearer), 0, 32);
    }
}
