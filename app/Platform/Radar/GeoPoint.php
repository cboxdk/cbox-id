<?php

declare(strict_types=1);

namespace App\Platform\Radar;

/**
 * A point on the earth. Distances are great-circle (haversine) on a sphere of the mean
 * radius — well inside what an impossible-travel check needs: the question is "a thousand
 * kilometres in five minutes", not metres.
 */
final readonly class GeoPoint
{
    public const float EARTH_RADIUS_KM = 6371.0088;

    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    public function distanceKmTo(self $other): float
    {
        $lat1 = deg2rad($this->latitude);
        $lat2 = deg2rad($other->latitude);
        $dLat = deg2rad($other->latitude - $this->latitude);
        $dLon = deg2rad($other->longitude - $this->longitude);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }
}
