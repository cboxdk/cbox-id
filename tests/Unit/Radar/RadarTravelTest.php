<?php

declare(strict_types=1);

use App\Platform\Radar\GeoPoint;
use App\Platform\Radar\RadarTravel;

/*
|--------------------------------------------------------------------------
| Impossible travel: the distance, and the speed it implies.
|--------------------------------------------------------------------------
*/

it('measures great-circle distance', function (GeoPoint $from, GeoPoint $to, float $km): void {
    expect($from->distanceKmTo($to))->toEqualWithDelta($km, $km * 0.005)
        ->and($to->distanceKmTo($from))->toEqualWithDelta($km, $km * 0.005);
})->with([
    'Copenhagen to New York' => [new GeoPoint(55.6761, 12.5683), new GeoPoint(40.7128, -74.0060), 6190.0],
    'Copenhagen to Stockholm' => [new GeoPoint(55.6761, 12.5683), new GeoPoint(59.3293, 18.0686), 522.0],
    'Sydney to London' => [new GeoPoint(-33.8688, 151.2093), new GeoPoint(51.5074, -0.1278), 16990.0],
    'across the antimeridian' => [new GeoPoint(0.0, 179.5), new GeoPoint(0.0, -179.5), 111.2],
]);

it('is zero from a point to itself', function (): void {
    expect((new GeoPoint(55.7, 12.6))->distanceKmTo(new GeoPoint(55.7, 12.6)))->toBe(0.0);
});

it('implies a speed from the distance and the time between sign-ins', function (): void {
    $copenhagen = new GeoPoint(55.6761, 12.5683);
    $newYork = new GeoPoint(40.7128, -74.0060);

    // Six hours apart: about 1,030 km/h — faster than a flight, door to door.
    expect(RadarTravel::speedKmh($copenhagen, 0, $newYork, 6 * 3600, 300.0))->toEqualWithDelta(1031.7, 10.0)
        // Two days apart: a plausible trip.
        ->and(RadarTravel::speedKmh($copenhagen, 0, $newYork, 48 * 3600, 300.0))->toBeLessThan(200.0);
});

it('does not call a short hop travel, however quick', function (): void {
    // Copenhagen to Malmö: 28 km. An IP is placed at a city at best.
    expect(RadarTravel::speedKmh(new GeoPoint(55.6761, 12.5683), 0, new GeoPoint(55.6050, 13.0038), 1, 300.0))->toBe(0.0);
});

it('never divides by less than a minute', function (): void {
    $from = new GeoPoint(55.6761, 12.5683);
    $to = new GeoPoint(40.7128, -74.0060);

    // The same second, or a clock that went backwards: the elapsed time is a minute.
    expect(RadarTravel::speedKmh($from, 1000, $to, 1000, 300.0))->toEqualWithDelta(6190.0 * 60, 6190.0 * 60 * 0.005)
        ->and(RadarTravel::speedKmh($from, 1000, $to, 900, 300.0))->toBe(RadarTravel::speedKmh($from, 1000, $to, 1000, 300.0));
});
