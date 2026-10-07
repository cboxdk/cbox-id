<?php

declare(strict_types=1);

namespace App\Platform\Health;

use App\Http\Controllers\HealthStatusController;
use Cbox\LaravelHealth\Checks\BaseCheck;
use Cbox\LaravelHealth\DataTransferObjects\CheckResult;

/**
 * `scheduler` on `/health/status`: red when the scheduler has not beaten recently.
 *
 * A MISSING beat is critical, not a warning. "Never heard from it" is exactly what a
 * deployment that forgot the `schedule:work` process looks like, and that is the failure
 * this check exists for; softening it to a warning would leave the endpoint green in the
 * one case that matters. A fresh deploy is red for at most a minute.
 *
 * On `/health/status`, never on readiness, for the same reason as the queue check: the
 * scheduler is a separate process, and its death must alert a person rather than pull
 * every web instance out of rotation — {@see HealthStatusController}.
 */
class SchedulerHealthCheck extends BaseCheck
{
    public function name(): string
    {
        return 'scheduler';
    }

    public function run(): CheckResult
    {
        $age = SchedulerHeartbeat::ageSeconds();
        $max = SchedulerHeartbeat::maxAgeSeconds();
        $metadata = ['age_seconds' => $age, 'max_age_seconds' => $max];

        if ($age === null) {
            return CheckResult::critical(
                $this->name(),
                'No scheduler heartbeat. Run `php artisan schedule:work` as a long-lived process: without it no webhook, '
                .'outbound SCIM or audit stream is ever sent.',
                $metadata,
            );
        }

        if ($age > $max) {
            return CheckResult::critical($this->name(), "The scheduler last ran {$age}s ago (limit {$max}s).", $metadata);
        }

        return CheckResult::ok($this->name(), "The scheduler ran {$age}s ago", $metadata);
    }
}
