<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Geo;

/**
 * A geographic point. Distances use the haversine formula on a spherical earth —
 * accurate to well within the tolerance an impossible-travel check needs (we care
 * about "1000 km in 5 minutes", not metres).
 */
final readonly class Coordinates
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    /**
     * Great-circle distance to another point, in kilometres.
     */
    public function distanceKmTo(self $other): float
    {
        $earthRadiusKm = 6371.0088;

        $lat1 = deg2rad($this->latitude);
        $lat2 = deg2rad($other->latitude);
        $dLat = deg2rad($other->latitude - $this->latitude);
        $dLon = deg2rad($other->longitude - $this->longitude);

        $a = sin($dLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 2 * $earthRadiusKm * asin(min(1.0, sqrt($a)));
    }
}
