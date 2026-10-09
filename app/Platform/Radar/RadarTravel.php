<?php

declare(strict_types=1);

namespace App\Platform\Radar;

/**
 * Impossible travel: how fast somebody would have had to move between the account's last
 * SUCCESSFUL sign-in and this attempt.
 *
 * Two allowances keep it honest about what geolocation by IP can do:
 *
 *  - hops shorter than the minimum distance (default 300 km) are not travel at all — an IP
 *    is placed at a city at best, often at its provider's nearest hub, and the stored point
 *    is rounded to ~11 km on purpose;
 *  - the elapsed time is never taken as less than a minute, so two sign-ins seconds apart
 *    give a large finite speed rather than a division by zero.
 */
final class RadarTravel
{
    public const int MIN_SECONDS = 60;

    /**
     * Implied speed in km/h, or 0.0 when the two points are closer than `$minDistanceKm`.
     */
    public static function speedKmh(GeoPoint $from, int $fromTimestamp, GeoPoint $to, int $toTimestamp, float $minDistanceKm): float
    {
        $distance = $from->distanceKmTo($to);

        if ($distance < $minDistanceKm) {
            return 0.0;
        }

        $seconds = max(self::MIN_SECONDS, $toTimestamp - $fromTimestamp);

        return $distance / ($seconds / 3600);
    }
}
