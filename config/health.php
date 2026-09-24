<?php

declare(strict_types=1);

use App\Platform\Health\QueueWorkersHealthCheck;
use Cbox\LaravelHealth\Checks\CacheCheck;
use Cbox\LaravelHealth\Checks\DatabaseCheck;
use Cbox\LaravelHealth\Checks\QueueCheck;
use Cbox\LaravelHealth\Checks\StorageCheck;

/*
|--------------------------------------------------------------------------
| Health endpoints
|--------------------------------------------------------------------------
|
| PUBLISHED RATHER THAN INHERITED, because the vendor default made the only
| endpoint that checks anything unreachable in production.
|
| `cboxdk/laravel-health` serves two endpoints. `/up` is liveness — a static
| answer meaning "this process is running", deliberately asserting nothing, and
| deliberately outside environment resolution, the issuer gate and (since
| laravel-id 0.92.0) the rate limiter, because a liveness probe that can 404,
| 403 or 500 restarts healthy instances.
|
| `/health/ready` is readiness, and it is the one that matters: it runs the
| database, cache, queue and storage checks. It is protected by `EndpointAuth`,
| which accepts a token — and, failing that, falls back to
| `app()->environment('local')`. With no token configured that fallback is FALSE
| in production, so readiness answered 403 to everything including the platform's
| own probe.
|
| The consequence is the one a readiness probe exists to prevent: an instance
| that comes up with a wrong DB_PASSWORD, a rotated-away crypto key or an
| unapplied migration answers 200 on `/up`, is added to the load balancer, the
| deploy reports success, and every console page and token request 500s.
|
| SET `HEALTH_TOKEN` IN THE DEPLOYMENT and point the platform's readiness probe
| at `/health/ready?token=…`. Without it this file changes nothing — it is here
| so the requirement is visible in the repository rather than discovered from a
| 403 during an incident.
|
| THE QUEUE WORKERS ARE NOT ON READINESS, and must never be. Readiness answers
| "can THIS instance serve web traffic?" and the platform routes on it (the
| Kubernetes id Deployment's readinessProbe is /health/ready). The queue manager is
| a separate process — a separate pod there — and its death marking every web
| instance unready would take the whole site down. `queue_workers` runs on
| /health/status instead (App\Http\Controllers\HealthStatusController), which
| answers 503 when anything is critical and which nothing routes on.
|
| Route on /up and /health/ready; alert on /health/status.
|
*/

return [
    'security' => [
        /*
         * The shared secret readiness requires. Absent → `EndpointAuth` falls back to
         * "are we local", which is false everywhere that matters.
         */
        'token' => env('HEALTH_TOKEN'),

        /*
         * Liveness only. Readiness names the database, the cache and the queue in its
         * response, so it stays behind the token: an unauthenticated caller must not be
         * able to enumerate which dependency of ours is currently unhappy.
         */
        'public_endpoints' => ['liveness'],
    ],

    /*
     * The vendor's endpoints, restated because a published key replaces the package's
     * whole block — with ONE change: the package's `status` route is off, because this
     * application serves its own at the same path (routes/health.php) with the `status`
     * checks below added.
     */
    'endpoints' => [
        'prefix' => env('HEALTH_PREFIX', 'health'),
        'liveness' => ['path' => '/', 'enabled' => true],
        'readiness' => ['path' => '/ready', 'enabled' => true],
        'startup' => ['path' => '/startup', 'enabled' => true],
        'status' => ['path' => '/status', 'enabled' => false],
        'metrics' => ['path' => '/metrics', 'enabled' => true],
        'json' => ['path' => '/metrics/json', 'enabled' => true],
        'ui' => ['path' => '/ui', 'enabled' => false],
    ],

    /*
     * The vendor's lists, restated for the same reason, plus `status`: checks that
     * ALERT and must never ROUTE. Readiness is exactly the package's and stays that way.
     */
    'checks' => [
        'liveness' => [
            DatabaseCheck::class,
        ],
        'readiness' => [
            DatabaseCheck::class,
            CacheCheck::class,
            QueueCheck::class,
            StorageCheck::class,
        ],
        'startup' => [],
        'status' => [
            QueueWorkersHealthCheck::class,
        ],
    ],
];
