<?php

declare(strict_types=1);

namespace App\Platform\Radar;

/**
 * IP addresses and ranges in one canonical spelling, so `2001:DB8::1` on a list matches
 * `2001:db8:0:0:0:0:0:1` on a request.
 */
final class RadarAddresses
{
    public static function canonicalIp(string $ip): ?string
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $canonical = inet_ntop($packed);

        return $canonical === false ? null : $canonical;
    }

    /** An address, or `address/prefix` with a prefix the address family allows. */
    public static function canonicalCidr(string $range): ?string
    {
        $range = trim($range);

        if (! str_contains($range, '/')) {
            return self::canonicalIp($range);
        }

        [$address, $prefix] = explode('/', $range, 2);
        $ip = self::canonicalIp($address);

        if ($ip === null || preg_match('/^\d{1,3}$/', $prefix) !== 1) {
            return null;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        if ((int) $prefix > $max) {
            return null;
        }

        return $ip.'/'.(int) $prefix;
    }
}
