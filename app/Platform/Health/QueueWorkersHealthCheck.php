<?php

declare(strict_types=1);

namespace App\Platform\Health;

use App\Http\Controllers\HealthStatusController;
use App\Platform\Queues\Contracts\QueueHealth;
use Cbox\LaravelHealth\Checks\BaseCheck;
use Cbox\LaravelHealth\DataTransferObjects\CheckResult;

/**
 * `queue_workers` on `/health/status`: red when no queue manager is alive, or when a
 * supervised queue's oldest job has waited longer than its pickup SLA.
 *
 * WHY IT EXISTS. The queue carries back-channel logout and every webhook, and nothing
 * about a missing worker is loud: the web tier answers 200, jobs pile up in Redis, and
 * relying parties simply stop hearing that people signed out. This deployment ran that
 * way for weeks. This is what an alert watches.
 *
 * WHY IT IS NOT ON READINESS. Readiness answers one question — can THIS instance serve
 * web traffic? — and the platform routes on the answer (on Kubernetes, the id Deployment's
 * readinessProbe is `/health/ready`). The queue manager is a separate process, on
 * Kubernetes a separate pod; if its death made readiness red, every web instance would be
 * pulled from the load balancer at once and the whole site would go down because a
 * background process stopped. So readiness keeps its own checks, and this runs on the
 * status endpoint, which nothing routes on — {@see HealthStatusController}.
 *
 * The metadata is counts, ages and names — never job contents — because health bodies
 * are read by monitoring systems with their own access model.
 */
class QueueWorkersHealthCheck extends BaseCheck
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
