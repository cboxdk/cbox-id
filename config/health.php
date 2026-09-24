<?php

declare(strict_types=1);

use App\Platform\Health\QueueWorkersReadinessCheck;
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
| READINESS ALSO ANSWERS FOR THE QUEUE WORKERS (`queue_workers`): red when no
| `queue:autoscale` manager has reported in, or a queue's oldest job has waited
| past its pickup SLA. That makes it the right thing to ALERT on and the wrong
| thing to ROUTE on — a load balancer that pulls instances on a red readiness
| would take the whole web tier down because a background process died. Route on
| `/up`; alert on `/health/ready`. See docs/operations/queue-workers.md.
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
     * The vendor's lists, restated because a published `checks` key replaces the
     * package's whole block rather than merging into it — and extended by one.
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
            QueueWorkersReadinessCheck::class,
        ],
        'startup' => [],
    ],
];
