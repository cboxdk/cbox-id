<?php

declare(strict_types=1);

namespace App\Platform\Health;

use App\Platform\Queues\Contracts\QueueHealth;
use Cbox\LaravelHealth\Checks\BaseCheck;
use Cbox\LaravelHealth\DataTransferObjects\CheckResult;

/**
 * `queue_workers` on `/health/ready`: red when no queue manager is alive, or when a
 * supervised queue's oldest job has waited longer than its pickup SLA.
 *
 * WHY READINESS AND NOT A LOG LINE. The queue carries back-channel logout and every
 * webhook, and nothing about a missing worker is loud: the web tier answers 200, jobs
 * pile up in Redis, and relying parties simply stop hearing that people signed out. This
 * deployment ran that way for weeks. `/health/ready` is the endpoint an uptime monitor
 * already watches (with `HEALTH_TOKEN`), so this is where the absence becomes visible.
 *
 * WHAT IT DOES NOT DO is take web traffic away. Liveness (`/up`) stays green, and it is
 * `/up` the platform's own probe routes on — a web instance must never be pulled out of
 * rotation because a separate background process died. Point alerting at readiness, not
 * the load balancer. See docs/operations/queue-workers.md.
 *
 * The metadata is counts, ages and names — never job contents — because readiness bodies
 * are read by monitoring systems with their own access model.
 */
class QueueWorkersReadinessCheck extends BaseCheck
{
    public function __construct(private readonly QueueHealth $health) {}

    public function name(): string
    {
        return 'queue_workers';
    }

    public function run(): CheckResult
    {
        $report = $this->health->inspect();
        $metadata = $report->toArray();

        if (! $report->healthy()) {
            return CheckResult::critical($this->name(), implode(' ', $report->problems()), $metadata);
        }

        return CheckResult::ok($this->name(), 'Queue manager running; every queue within its pickup SLA', $metadata);
    }
}
