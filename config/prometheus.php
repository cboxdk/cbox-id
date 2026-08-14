<?php

declare(strict_types=1);
use App\Http\Middleware\AllowNamedIpsOnly;

/*
 * Metrics are OFF unless somebody turns them on, and locked to named addresses when they do.
 *
 * `spatie/laravel-prometheus` arrived transitively — `cboxdk/laravel-queue-metrics` requires
 * it — and nothing in this application registers a collector or references it. Its own
 * defaults are `enabled => true`, a `/prometheus` route, and `allowed_ips => []`, which the
 * package's comment documents as "All IP's are allowed when empty". So an unauthenticated
 * `/prometheus` was served in production by a dependency nobody chose to expose.
 *
 * It answered an empty body, because there is nothing collecting — which is precisely what
 * makes it the wrong thing to leave alone: it is a door that starts emitting the day
 * anybody registers a collector, and by then nobody will remember it was open.
 *
 * Publishing this file is how the app states its own position rather than inheriting one.
 */
return [
    // The whole surface, refused unless an operator asks for it. `PROMETHEUS_ENABLED=true`
    // plus `PROMETHEUS_ALLOWED_IPS` is the deliberate act.
    'enabled' => env('PROMETHEUS_ENABLED', false),

    'urls' => [
        'default' => 'prometheus',
    ],

    /*
     * AND EVEN THEN, ONLY FROM NAMED ADDRESSES. An empty list means "everybody" to this
     * package — its own `AllowIps` returns `$next($request)` when the list is empty — so
     * the middleware below is OURS. With the feature enabled and no addresses listed it
     * refuses every request, which is the failure direction that costs a scrape rather
     * than a disclosure.
     *
     * This paragraph used to describe the vendor's middleware doing that, which it never
     * did. See {@see AllowNamedIpsOnly}.
     */
    'allowed_ips' => array_values(array_filter(
        explode(',', (string) env('PROMETHEUS_ALLOWED_IPS', '')),
        static fn (string $ip): bool => trim($ip) !== '',
    )),

    'default_namespace' => 'app',

    'middleware' => [
        AllowNamedIpsOnly::class,
    ],
];
