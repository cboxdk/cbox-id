<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Geo;

use Cbox\Id\RiskPlus\Contracts\GeoLocator;

/**
 * The default locator: it locates nothing. With it bound, the impossible-travel
 * signal is inert (it never has two points to compare), so installing the plugin
 * without wiring a real GeoLocator is safe — no false positives from a missing
 * geo source. Bind a real {@see GeoLocator} to switch geo scoring on.
 */
class NullGeoLocator implements GeoLocator
{
    public function locate(string $ip): ?Coordinates
    {
        return null;
    }
}
