<?php

declare(strict_types=1);

namespace App\Platform\Health;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Proof that the scheduler is running: a timestamp it writes every minute.
 *
 * WHY IT EXISTS. Every background duty this platform has — the domain-event relay that
 * feeds webhooks, outbound SCIM, the audit-stream pump, token revocation on role change,
 * pruning — is a SCHEDULED command. A deployment that runs the web tier and the queue
 * manager but no `schedule:work` looks completely healthy and does none of it. Nothing
 * else notices: the relay simply never runs, so there is no error to log.
 *
 * The key is the one `cboxdk/laravel-health`'s own ScheduleCheck reads, so the vendor
 * check and ours agree on what "the scheduler is alive" means.
 */
final class SchedulerHeartbeat
{
    public const string KEY = 'health:schedule:heartbeat';

    /** How long a beat is kept: long enough to report "stale for an hour", no longer. */
    private const int TTL_SECONDS = 3600;

    public static function beat(): void
    {
        Cache::put(self::KEY, Carbon::now(), self::TTL_SECONDS);
    }

    /** Seconds since the last beat; null when there is none (never ran, or long gone). */
    public static function ageSeconds(): ?int
    {
        $last = Cache::get(self::KEY);

        if (! $last instanceof Carbon) {
            return null;
        }

        return max(0, (int) $last->diffInSeconds(Carbon::now(), absolute: false));
    }

    /** The oldest a beat may be before the scheduler counts as stopped. */
    public static function maxAgeSeconds(): int
    {
        $configured = config('health.checks_config.scheduler.max_age_seconds', 300);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 300;
    }
}
