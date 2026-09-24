<?php

declare(strict_types=1);

namespace App\Platform\Queues;

use Cbox\LaravelQueueAutoscale\Contracts\ProfileContract;
use Cbox\LaravelQueueAutoscale\Scaling\Calculators\LinearRegressionForecaster;
use Cbox\LaravelQueueAutoscale\Scaling\Forecasting\Policies\ModerateForecastPolicy;

/**
 * How this application's queue workers are sized — every number stated, none inherited.
 *
 * WHAT RUNS ON THE QUEUE is small and outbound: a webhook POST (10 s total HTTP timeout),
 * a back-channel logout token to one relying party (30 s job timeout), an app manifest
 * pull, a push notification, a SIEM batch, a Postal delivery report, and whatever mail
 * and listeners the framework queues. None of it is CPU-bound; all of it waits on
 * somebody else's server. So the profile is shaped for latency and a small host, not for
 * throughput:
 *
 *  - SLA 30 s. Somebody who signs out expects the apps they used to follow within
 *    seconds, and a webhook receiver expects an event about as fast. 30 s is the tightest
 *    target the autoscaler's poll loop honours without flapping (its own floor is ~5 s,
 *    and sub-15 s targets breach on every burst), and it is also the line the health
 *    check ({@see LiveQueueHealth}) turns red at — one number, stated once.
 *  - min 1. The floor is never zero: back-channel logout and webhooks are work nobody is
 *    watching for, and a scale-from-zero cold start (5–10 s) spent on every sign-out is
 *    latency for nothing. A worker polling an empty queue costs one sleeping process.
 *  - max 2. This deployment's App instance is 512 MB and the same instance serves the web
 *    traffic. The hard stop is `limits.max_total_workers` in config/queue-autoscale.php;
 *    this is the per-group ceiling beneath it. Raise both together when the workers get
 *    a Worker cluster of their own.
 *  - timeout 75 s, BELOW the Redis `retry_after` of 90 s. A job allowed to run past
 *    `retry_after` is handed to a second worker while the first is still sending it —
 *    a webhook or a logout token delivered twice. The package default is 300 s, which
 *    would do exactly that. The longest job here is the back-channel logout's own 30 s.
 *  - tries 3 for any job that does not state its own. Every job with a real retry policy
 *    (logout's backoff ladder, Postal's 3) states it on the job, which wins.
 *  - max_time 3600 s. Workers are recycled hourly so a slow leak cannot accumulate.
 *  - sleep 3 s between polls of an empty queue — the trade between pickup latency and
 *    Redis round-trips on an idle install.
 *
 * The fuse stays on: a relying party that is down fails every logout delivery sent to it,
 * and the right answer to that is NOT more workers hammering it.
 */
readonly class WorkerProfile implements ProfileContract
{
    /** The pickup SLA, in seconds — the autoscaler's target and the health check's red line. */
    public const SLA_SECONDS = 30;

    /** Per-job timeout. Must stay below the queue connection's `retry_after` (90 s). */
    public const JOB_TIMEOUT_SECONDS = 75;

    public function resolve(): array
    {
        return [
            'sla' => [
                'target_seconds' => self::SLA_SECONDS,
                'percentile' => 95,
                'window_seconds' => 300,
                'min_samples' => 20,
            ],
            'forecast' => [
                'forecaster' => LinearRegressionForecaster::class,
                'policy' => ModerateForecastPolicy::class,
                'horizon_seconds' => 60,
                'history_seconds' => 300,
            ],
            'workers' => [
                'min' => 1,
                'max' => 2,
                'tries' => 3,
                'max_time_seconds' => 3600,
                'timeout_seconds' => self::JOB_TIMEOUT_SECONDS,
                'sleep_seconds' => 3,
                'shutdown_timeout_seconds' => 30,
            ],
            'spawn_compensation' => [
                'enabled' => true,
                'fallback_seconds' => 2.0,
                'min_samples' => 5,
                'ema_alpha' => 0.2,
            ],
            'fuse' => [
                'enabled' => true,
                'failure_threshold_percent' => 50.0,
                'min_samples' => 20,
                'window_seconds' => 60,
                'cooldown_seconds' => 60,
            ],
        ];
    }
}
