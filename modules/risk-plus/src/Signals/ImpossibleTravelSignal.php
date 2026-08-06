<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Signals;

use Cbox\Id\RiskPlus\Contracts\GeoLocator;
use Cbox\Id\RiskPlus\Geo\Coordinates;
use Cbox\Id\RiskPlus\Support\SubjectKey;
use Cbox\Risk\Contracts\Signal;
use Cbox\Risk\ValueObjects\RiskContext;
use Cbox\Risk\ValueObjects\SignalResult;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;

/**
 * Scores "impossible travel": the same account seen from two locations too far
 * apart to have travelled between in the elapsed time. It compares this sign-in's
 * geolocated IP with the account's last-seen location and flags an implied speed
 * above a plausible ceiling (default 900 km/h — faster than a commercial flight).
 *
 * It fails open at every step — no subject to key on, no IP, no locator result, a
 * too-short hop, or a non-positive interval all yield "no signal", never a block.
 * Locations are keyed by the HMAC'd subject only; the last-seen point is stored
 * with a TTL and overwritten each sign-in, so no durable location history is kept.
 */
class ImpossibleTravelSignal implements Signal
{
    public function __construct(
        private readonly GeoLocator $locator,
        private readonly SubjectKey $subjectKey,
        private readonly Cache $cache,
        private readonly float $maxSpeedKmh = 900.0,
        private readonly float $minDistanceKm = 50.0,
        private readonly float $points = 60.0,
        private readonly int $ttlSeconds = 604800,
    ) {}

    public function key(): string
    {
        return 'geo.impossible_travel';
    }

    public function evaluate(RiskContext $context): ?SignalResult
    {
        $subject = $this->subjectKey->for($context);

        if ($subject === null || $context->ip === null) {
            return null;
        }

        $current = $this->locator->locate($context->ip);

        if ($current === null) {
            return null;
        }

        $now = Carbon::now()->getTimestamp();
        $bucket = 'cbox-riskplus:geo:'.$subject;
        $prior = $this->readPrior($this->cache->get($bucket));

        // Record this sighting for next time, regardless of the outcome.
        $this->cache->put($bucket, [
            'lat' => $current->latitude,
            'lon' => $current->longitude,
            'at' => $now,
        ], $this->ttlSeconds);

        if ($prior === null) {
            return null;
        }

        [$priorCoords, $priorAt] = $prior;
        $elapsedSeconds = $now - $priorAt;
        $distanceKm = $current->distanceKmTo($priorCoords);

        // Ignore same-city hops and non-positive intervals (clock skew / replay).
        if ($elapsedSeconds <= 0 || $distanceKm < $this->minDistanceKm) {
            return null;
        }

        $impliedKmh = $distanceKm / ($elapsedSeconds / 3600);

        if ($impliedKmh <= $this->maxSpeedKmh) {
            return null;
        }

        return new SignalResult(
            $this->key(),
            $this->points,
            sprintf('implied travel of %d km/h exceeds %d km/h', (int) round($impliedKmh), (int) $this->maxSpeedKmh),
            ['distance_km' => round($distanceKm, 1), 'seconds' => $elapsedSeconds, 'kmh' => round($impliedKmh)],
        );
    }

    /**
     * @return array{0: Coordinates, 1: int}|null
     */
    private function readPrior(mixed $stored): ?array
    {
        if (! is_array($stored)) {
            return null;
        }

        $lat = $stored['lat'] ?? null;
        $lon = $stored['lon'] ?? null;
        $at = $stored['at'] ?? null;

        if ((! is_float($lat) && ! is_int($lat)) || (! is_float($lon) && ! is_int($lon)) || ! is_int($at)) {
            return null;
        }

        return [new Coordinates((float) $lat, (float) $lon), $at];
    }
}
