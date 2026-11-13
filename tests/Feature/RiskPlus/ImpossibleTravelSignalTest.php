<?php

declare(strict_types=1);

use Cbox\Id\RiskPlus\Signals\ImpossibleTravelSignal;
use Cbox\Id\RiskPlus\Support\SubjectKey;
use Cbox\Id\RiskPlus\Testing\FakeGeoLocator;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * @param  array<string, array{0: float, 1: float}>  $locations  ip => [lat, lon]
 */
function travelSignal(array $locations): ImpossibleTravelSignal
{
    $locator = new FakeGeoLocator;

    foreach ($locations as $ip => [$lat, $lon]) {
        $locator->at($ip, $lat, $lon);
    }

    return new ImpossibleTravelSignal(
        $locator,
        new SubjectKey('test-secret'),
        new Repository(new ArrayStore),
    );
}

function loginFrom(string $ip): RiskContext
{
    return new RiskContext(action: 'login', ip: $ip, email: 'user@example.com');
}

it('stays quiet on the first sighting (no prior to compare)', function (): void {
    $signal = travelSignal(['1.1.1.1' => [40.71, -74.01]]); // New York

    expect($signal->evaluate(loginFrom('1.1.1.1')))->toBeNull();
});

it('fires when two sign-ins imply impossible travel speed', function (): void {
    // New York, then Tokyo (~10,800 km) an hour later — ~10,800 km/h.
    $signal = travelSignal([
        '1.1.1.1' => [40.71, -74.01],
        '2.2.2.2' => [35.68, 139.69],
    ]);

    $this->travelTo('2026-07-16 12:00:00');
    expect($signal->evaluate(loginFrom('1.1.1.1')))->toBeNull();
    $signal->remember(loginFrom('1.1.1.1'));

    $this->travelTo('2026-07-16 13:00:00');
    $result = $signal->evaluate(loginFrom('2.2.2.2'));

    expect($result)->not->toBeNull()
        ->and($result->key)->toBe('geo.impossible_travel')
        ->and($result->points)->toBe(60.0);
});

it('does not fire for plausible travel (same city, an hour apart)', function (): void {
    $signal = travelSignal([
        '1.1.1.1' => [40.71, -74.01],
        '3.3.3.3' => [40.75, -73.99], // ~5 km away, under the min-distance floor
    ]);

    $this->travelTo('2026-07-16 12:00:00');
    $signal->remember(loginFrom('1.1.1.1'));

    $this->travelTo('2026-07-16 13:00:00');
    expect($signal->evaluate(loginFrom('3.3.3.3')))->toBeNull();
});

it('is inert without a located IP (null locator = no false positives)', function (): void {
    $signal = travelSignal([]); // locator knows nothing

    $this->travelTo('2026-07-16 12:00:00');
    expect($signal->evaluate(loginFrom('9.9.9.9')))->toBeNull();
    $signal->remember(loginFrom('9.9.9.9'));

    $this->travelTo('2026-07-16 20:00:00');
    expect($signal->evaluate(loginFrom('8.8.8.8')))->toBeNull();
});

it('measures from the last successful sign-in, not from an attempt somebody only scored', function (): void {
    $signal = travelSignal([
        '1.1.1.1' => [40.71, -74.01], // New York, where the owner signs in
        '2.2.2.2' => [35.68, 139.69], // Tokyo, where somebody only typed the address
    ]);

    $this->travelTo('2026-07-16 12:00:00');
    $signal->remember(loginFrom('1.1.1.1'));

    $this->travelTo('2026-07-16 12:30:00');
    $signal->evaluate(loginFrom('2.2.2.2'));

    // Back in New York: no hop happened, so nothing is impossible.
    $this->travelTo('2026-07-16 13:00:00');
    expect($signal->evaluate(loginFrom('1.1.1.1')))->toBeNull();
});
