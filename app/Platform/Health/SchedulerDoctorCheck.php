<?php

declare(strict_types=1);

namespace App\Platform\Health;

use Cbox\Id\Console\Contracts\HealthCheck;
use Cbox\Id\Console\ValueObjects\HealthResult;
use Cbox\Id\Kernel\Events\Contracts\RelayBacklog;

/**
 * `cbox-id:doctor`'s view of the scheduler and the event relay it drives.
 *
 * A missing heartbeat FAILS in production and only warns elsewhere: a developer running
 * `composer run dev` has no scheduler and does not need to be told so in red, while a
 * production deployment without one delivers no webhooks at all.
 */
class SchedulerDoctorCheck implements HealthCheck
{
    public function __construct(private readonly RelayBacklog $backlog) {}

    public function run(): array
    {
        $age = SchedulerHeartbeat::ageSeconds();
        $max = SchedulerHeartbeat::maxAgeSeconds();

        $missing = 'Run `php artisan schedule:work` as a long-lived process. Without it the event relay, outbound '
            .'SCIM, the audit-stream pump and pruning never run.';

        $results = [
            match (true) {
                $age !== null && $age <= $max => HealthResult::ok('Scheduler running', "last ran {$age}s ago"),
                app()->isProduction() => HealthResult::fail(
                    'Scheduler not running',
                    $age === null ? 'No heartbeat. '.$missing : "Last ran {$age}s ago (limit {$max}s). ".$missing,
                ),
                default => HealthResult::warn('Scheduler not running', $missing),
            },
        ];

        $depth = $this->backlog->depth();
        $lag = $depth->oldestAgeSeconds();
        $limit = EventRelayHealthCheck::maxLagSeconds();

        $results[] = $lag > $limit
            ? HealthResult::fail(
                'Event relay is behind',
                "The oldest undelivered event has waited {$lag}s (limit {$limit}s); {$depth->total()} waiting.",
            )
            : HealthResult::ok('Event relay', "{$depth->total()} waiting, oldest {$lag}s");

        return $results;
    }
}
