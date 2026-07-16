<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Testing;

use Cbox\Id\RiskPlus\Contracts\GeoLocator;
use Cbox\Id\RiskPlus\Geo\Coordinates;

/**
 * A {@see GeoLocator} you program by hand — map IPs to coordinates for tests and
 * local development, with no GeoIP database. Bind it in place of the null locator
 * to exercise the impossible-travel signal.
 */
final class FakeGeoLocator implements GeoLocator
{
    /**
     * @var array<string, Coordinates>
     */
    private array $map = [];

    public function at(string $ip, float $latitude, float $longitude): self
    {
        $this->map[$ip] = new Coordinates($latitude, $longitude);

        return $this;
    }

    public function locate(string $ip): ?Coordinates
    {
        return $this->map[$ip] ?? null;
    }
}
