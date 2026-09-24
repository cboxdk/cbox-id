<?php

declare(strict_types=1);

use App\Platform\Queues\DispatchedQueues;
use App\Platform\Queues\WorkerProfile;
use Cbox\LaravelQueueAutoscale\Fuse\ConfigurableFailureClassifier;
use Cbox\LaravelQueueAutoscale\Pickup\SortBasedPercentileCalculator;
use Cbox\LaravelQueueAutoscale\Policies\BreachNotificationPolicy;
use Cbox\LaravelQueueAutoscale\Policies\ConservativeScaleDownPolicy;
use Cbox\LaravelQueueAutoscale\Scaling\Strategies\HybridStrategy;

/*
|--------------------------------------------------------------------------
| Queue workers — cboxdk/laravel-queue-autoscale
|--------------------------------------------------------------------------
|
| PUBLISHED RATHER THAN INHERITED. Nothing processed this application's queue in
| production for weeks: webhooks, back-channel logout, manifest sync and Postal
| delivery reports all sat in Redis because no worker was ever started. The manager
| is the worker now — run exactly ONE `php artisan queue:autoscale` per host and it
| spawns, sizes and reaps the `queue:work` processes itself. See
| docs/operations/queue-workers.md.
|
| Every value below is chosen for the smallest shape this runs on: ONE 512 MB
| instance that also serves the web traffic. Where a value differs from the
| package default, the comment says why.
|
*/

// Where the application's jobs go when a dispatcher names no queue of its own. Mirrors
// config/queue.php: the default connection, and that connection's own default queue.
$defaultConnection = (string) env('QUEUE_CONNECTION', 'database');

$dispatched = DispatchedQueues::resolve(
    defaultConnection: $defaultConnection,
    defaultQueueFor: [
        'redis' => (string) env('REDIS_QUEUE', 'default'),
        'database' => (string) env('DB_QUEUE', 'default'),
        'sqs' => (string) env('SQS_QUEUE', 'default'),
        'beanstalkd' => (string) env('BEANSTALKD_QUEUE', 'default'),
    ],
    routes: [
        [env('CBOX_ID_WEBHOOKS_QUEUE_CONNECTION'), env('CBOX_ID_WEBHOOKS_QUEUE')],
        [env('CBOX_ID_DEVICES_QUEUE_CONNECTION'), env('CBOX_ID_DEVICES_QUEUE')],
        [env('POSTAL_WEBHOOK_CONNECTION'), env('POSTAL_WEBHOOK_QUEUE')],
        [env('POSTAL_INBOUND_CONNECTION'), env('POSTAL_INBOUND_QUEUE')],
        [env('SIEM_QUEUE_CONNECTION'), env('SIEM_QUEUE', 'default')],
    ],
);

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),
    'manager_id' => env('QUEUE_AUTOSCALE_MANAGER_ID'),

    // Any queue the autoscaler discovers that is NOT one of ours below (a one-off queue
    // somebody dispatched to by hand) still gets these settings, with a floor of zero.
    'sla_defaults' => WorkerProfile::class,

    /*
     * No per-queue entries: every queue this application dispatches to is covered by a
     * GROUP below, which is what gives each of them a floor of one worker.
     *
     * Do not add an entry here without its `connection`. The autoscaler keys a queue by
     * `{connection}:{queue}` and a missing connection is read as the literal connection
     * name `default` — which no entry in config/queue.php is called — so it would spawn
     * `queue:work default`, which fails, while the real queue went unserved.
     */
    'queues' => [],

    'excluded' => [],

    /*
     * One worker group per queue connection, polling every queue the application
     * dispatches to on it, the default queue first. Derived — see {@see DispatchedQueues}
     * — so moving webhooks to their own queue moves their worker with them.
     */
    'groups' => $dispatched->autoscaleGroups(WorkerProfile::class),

    // Single host: no Redis-backed pickup store needed. `auto` switches to Redis only
    // when cluster mode is turned on.
    'pickup_time' => [
        'store' => env('QUEUE_AUTOSCALE_PICKUP_TIME_STORE', 'auto'),
        'percentile_calculator' => SortBasedPercentileCalculator::class,
        'max_samples_per_queue' => 1000,
        'sampling' => [
            'enabled' => env('QUEUE_AUTOSCALE_PICKUP_SAMPLING', true),
            'max_per_second' => env('QUEUE_AUTOSCALE_PICKUP_SAMPLES_PER_SECOND', 100),
        ],
    ],

    'spawn_latency' => [
        'tracker' => env('QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER', 'auto'),
    ],

    /*
     * The failure fuse STAYS ON. A relying party that is down fails every logout token
     * and webhook sent to it; the backlog grows, and an autoscaler without a fuse answers
     * that with more workers hammering the same dead endpoint and burning each job's
     * retries faster. The thresholds live in {@see WorkerProfile}.
     *
     * `auto` keeps the outcome counters in the application cache, which the workers and
     * the manager share — Redis in production.
     */
    'fuse' => [
        'enabled' => env('QUEUE_AUTOSCALE_FUSE_ENABLED', true),
        'store' => env('QUEUE_AUTOSCALE_FUSE_STORE', 'auto'),
        'ignored_exceptions' => [],
        'classifier' => ConfigurableFailureClassifier::class,
    ],

    'scaling' => [
        'fallback_job_time_seconds' => env('QUEUE_AUTOSCALE_FALLBACK_JOB_TIME', 2.0),
        'breach_threshold' => 0.5,
        // The health check derives how long a silent manager may stay silent from this
        // (a held scale-down emits nothing for up to this long). Keep it at 60 or below.
        'cooldown_seconds' => 60,
    ],

    /*
     * THE CEILINGS THAT KEEP THE WEB PROCESS ALIVE on a 512 MB instance.
     *
     * The budget, measured rather than guessed: a booted worker of this application is
     * ~75 MB resident (framework + laravel-id + every module), the manager about the
     * same, and PHP-FPM needs the rest for web requests. Two workers plus the manager is
     * ~230 MB, leaving ~280 MB for the web tier. So:
     *
     *  - max_total_workers 2 — a HARD cap, applied after everything else, and the one
     *    that holds even if the host's memory reading is wrong (a container reporting the
     *    node's memory instead of its own limit would make the percentage ceiling below
     *    meaningless). Raise it only with a larger instance or a Worker cluster.
     *  - max_memory_percent 70 — stop spawning while the instance is above 70 %, leaving
     *    headroom for a burst of web requests rather than racing them to the OOM killer.
     *  - worker_memory_mb_estimate 96 — the cold-start estimate before a measurement
     *    exists: the 75 MB boot plus a job's working set, rounded up.
     *  - max_cpu_percent 80, reserve_cpu_cores 0.25 — the web tier keeps a quarter core.
     */
    'limits' => [
        'max_cpu_percent' => 80,
        'max_memory_percent' => 70,
        'worker_memory_mb_estimate' => 96,
        'worker_cpu_core_estimate' => 0.25,
        'reserve_cpu_cores' => 0.25,
        'max_total_workers' => (int) env('QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS', 2),
    ],

    'manager' => [
        'evaluation_interval_seconds' => 5,
        'shutdown_grace_seconds' => 30,
        'log_channel' => env('QUEUE_AUTOSCALE_LOG_CHANNEL', 'stack'),
        'restart_scope' => env('QUEUE_AUTOSCALE_RESTART_SCOPE'),
        // `php artisan queue:restart` in a self-hosted deploy script restarts the manager
        // too. On Laravel Cloud the deploy replaces the instance, so nothing has to.
        'honor_queue_restart' => env('QUEUE_AUTOSCALE_HONOR_QUEUE_RESTART', true),
        'reap_orphans_on_start' => env('QUEUE_AUTOSCALE_REAP_ORPHANS_ON_START', true),
    ],

    /*
     * SINGLE-HOST MODE. There is one App instance and exactly one manager on it. Cluster
     * mode is for several hosts sharing one set of queues; turn it on (it needs Redis)
     * the day the App cluster autoscales past one replica, or two managers will each
     * size the pool as if alone — see docs/operations/queue-workers.md.
     */
    'cluster' => [
        'enabled' => env('QUEUE_AUTOSCALE_CLUSTER_ENABLED', false),
        'heartbeat_ttl_seconds' => env('QUEUE_AUTOSCALE_CLUSTER_HEARTBEAT_TTL', 15),
        'leader_lease_seconds' => env('QUEUE_AUTOSCALE_CLUSTER_LEADER_LEASE', 15),
        'recommendation_ttl_seconds' => env('QUEUE_AUTOSCALE_CLUSTER_RECOMMENDATION_TTL', 30),
        'summary_ttl_seconds' => env('QUEUE_AUTOSCALE_CLUSTER_SUMMARY_TTL', 30),
        'decision_history_seconds' => env('QUEUE_AUTOSCALE_DECISION_HISTORY', 3600),
        'decision_history_max' => env('QUEUE_AUTOSCALE_DECISION_HISTORY_MAX', 10000),
    ],

    'strategy' => HybridStrategy::class,

    'policies' => [
        ConservativeScaleDownPolicy::class,
        BreachNotificationPolicy::class,
    ],

    'alerting' => [
        'cooldown_seconds' => env('QUEUE_AUTOSCALE_ALERT_COOLDOWN', 300),
    ],

    'telemetry' => [
        'enabled' => env('QUEUE_AUTOSCALE_TELEMETRY_ENABLED', true),
        'cache_ttl' => env('QUEUE_AUTOSCALE_TELEMETRY_CACHE_TTL', 10),
        'max_queue_labels' => env('QUEUE_AUTOSCALE_TELEMETRY_MAX_QUEUE_LABELS', 100),
        'gauges' => [
            'cluster' => true,
        ],
        'events' => true,
    ],
];
