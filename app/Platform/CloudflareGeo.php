<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Http\Request;

/**
 * WHERE CLOUDFLARE SAYS A VISITOR IS — read from its request headers, and nothing else.
 *
 * No GeoIP database and no lookup service: the edge already resolved the address, and
 * these headers are the whole answer. They belong to the visitor only on the configured
 * ingress path, so a request that did not come through a trusted proxy has no location at
 * all — a client talking to the origin directly can send any `CF-IPCountry` it likes.
 *
 * The address itself is never part of this object.
 */
final readonly class CloudflareGeo
{
    private const int TEXT_MAX = 160;

    public function __construct(
        /** ISO 3166-1 alpha-2, upper case. */
        public string $countryCode,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $regionCode = null,
        public ?string $timezone = null,
    ) {}

    /** The visitor's location as the trusted edge reported it, or null when it did not. */
    public static function fromRequest(Request $request): ?self
    {
        if (! $request->isFromTrustedProxy()) {
            return null;
        }

        // Header names are case-insensitive (HeaderBag normalises them).
        $country = strtoupper(trim((string) $request->header('CF-IPCountry')));

        // Cloudflare uses XX for an unknown country and T1 for Tor; neither is a place.
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || $country === 'XX') {
            return null;
        }

        $regionCode = self::text($request, 'CF-Region-Code');

        return new self(
            countryCode: $country,
            city: self::text($request, 'CF-IPCity'),
            region: self::text($request, 'CF-Region'),
            regionCode: $regionCode !== null && preg_match('/^[A-Za-z0-9-]{1,10}$/', $regionCode) === 1 ? strtoupper($regionCode) : null,
            timezone: ($zone = self::text($request, 'CF-Timezone')) !== null && preg_match('#^[A-Za-z_]+(?:/[A-Za-z0-9_+-]+){0,2}$#', $zone) === 1 ? $zone : null,
        );
    }

    /** A header as one line of display text: URL-decoded, valid UTF-8, no control characters. */
    private static function text(Request $request, string $header): ?string
    {
        $value = trim(rawurldecode((string) $request->header($header)));

        return $value !== '' && mb_check_encoding($value, 'UTF-8') && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
            ? mb_substr($value, 0, self::TEXT_MAX)
            : null;
    }
}
