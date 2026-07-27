<?php

declare(strict_types=1);

return [

    /*
     * Impossible-travel signal. `max_speed_kmh` is the fastest plausible travel
     * between two sign-ins before it's flagged (900 ≈ faster than a jet). Hops
     * shorter than `min_distance_km` are ignored (same metro / GeoIP jitter).
     * `points` is what it contributes on a hit; the last-seen point is kept for
     * `ttl` seconds. Inert until a real GeoLocator is bound.
     */
    'impossible_travel' => [
        'max_speed_kmh' => 900,
        'min_distance_km' => 50,
        'points' => 60,
        'ttl' => 604800,
    ],

    /*
     * New-device signal. `points` is scored the first time an account is seen on a
     * given device fingerprint; up to `max_devices` fingerprints are remembered per
     * account for `ttl` seconds. The first device an account ever uses is enrolment,
     * not a new-device event.
     */
    'new_device' => [
        'points' => 35,
        'max_devices' => 20,
        'ttl' => 7776000,
    ],
];
