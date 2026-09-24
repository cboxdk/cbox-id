<?php

declare(strict_types=1);

namespace App\Platform\Queues;

use App\Platform\Queues\Contracts\ManagerHeartbeat;
use App\Platform\Queues\Contracts\QueueHealth;
use Carbon\CarbonImmutable;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\QueueConfiguration;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Throwable;

/**
 * Reads the queues themselves, not the manager's opinion of them.
 *
 * TWO INDEPENDENT SIGNALS, and it takes both to be green:
 *
 *  1. A manager beat recently ({@see ManagerHeartbeat}). This catches the failure this
 *     deployment actually had — no manager at all — before a single job has had time to
 *     age: an install where nothing is dispatched would otherwise read green forever.
 *  2. No supervised queue's oldest waiting job is older than that queue's pickup SLA.
 *     Read straight from the queue driver, so it holds even when the manager is running
 *     but its workers are not getting through — and even when nobody runs the manager
 *     at all and plain `queue:work` does the job instead.
 *
 * WHICH QUEUES: exactly the ones the autoscale config supervises, read through the
 * package's own parsers so the check and the manager can never disagree about the set or
 * the SLA. See {@see DispatchedQueues} for how that set is derived.
 *
 * One caveat worth knowing, stated rather than hidden: on Redis a job released with a
 * delay (a back-channel logout retry, say) keeps its original creation time, so for the
 * few seconds between becoming due and a worker taking it, it can read as older than the
 * SLA. A healthy worker takes it on its next poll; a red that persists is real.
 */
class LiveQueueHealth implements QueueHealth
{
    /** Never judge a manager silent sooner than this, whatever the config says. */
    private const MINIMUM_STALE_SECONDS = 60;

    public function __construct(
        private readonly ManagerHeartbeat $heartbeat,
        private readonly QueueFactory $queues,
    ) {}

    public function inspect(): QueueHealthReport
    {
        $now = CarbonImmutable::now();

        try {
            $watched = $this->watched();
        } catch (Throwable $e) {
            // An autoscale config the package refuses is a manager that is not serving
            // anything — red, with the package's own reason.
            return new QueueHealthReport(
                manager: $this->manager(supervised: true),
                queues: [new QueueBacklog('queue-autoscale', 'config', 0, unreadable: $e->getMessage())],
                checkedAt: $now,
            );
        }

        return new QueueHealthReport(
            manager: $this->manager(supervised: AutoscaleConfiguration::isEnabled() && $watched !== []),
            queues: array_map(fn (SupervisedQueue $queue): QueueBacklog => $this->backlog($queue, $now), $watched),
            checkedAt: $now,
        );
    }

    /**
     * How long a manager may go without a beat before it counts as missing.
     *
     * The manager beats every evaluation interval, EXCEPT while the anti-flapping window
     * holds a scale-down — then it evaluates, decides nothing, and says nothing, for up to
     * `scaling.cooldown_seconds`. So the window is two cooldowns plus a few intervals: long
     * enough that a held scale-down never reads as a dead manager, short enough that a
     * dead one is red within a couple of minutes.
     */
    public function staleAfterSeconds(): int
    {
        $cooldown = config('queue-autoscale.scaling.cooldown_seconds', 60);
        $cooldown = is_numeric($cooldown) ? (int) $cooldown : 60;

        return max(self::MINIMUM_STALE_SECONDS, 2 * $cooldown + 4 * AutoscaleConfiguration::evaluationIntervalSeconds());
    }

    private function manager(bool $supervised): ManagerStatus
    {
        $stale = $this->staleAfterSeconds();

        try {
            $beat = $this->heartbeat->last();
        } catch (Throwable) {
            // The cache is down. The readiness check's own cache probe says so; here it
            // means we cannot vouch for the manager, which is the same as not hearing it.
            $beat = null;
        }

        if (! $supervised) {
            return new ManagerStatus(ManagerState::NotSupervised, $beat, $stale);
        }

        $fresh = $beat !== null && $beat->at->greaterThanOrEqualTo(CarbonImmutable::now()->subSeconds($stale));

        return new ManagerStatus($fresh ? ManagerState::Running : ManagerState::Missing, $beat, $stale);
    }

    /**
     * Every queue the autoscaler supervises, with the SLA it holds that queue to.
     *
     * @return list<SupervisedQueue>
     */
    private function watched(): array
    {
        $watched = [];

        foreach (GroupConfiguration::allFromConfig() as $group) {
            foreach ($group->queues as $queue) {
                $target = new DispatchedQueue($group->connection, $queue);
                $watched[$target->key()] = new SupervisedQueue($target, $group->sla->targetSeconds);
            }
        }

        foreach (AutoscaleConfiguration::configuredQueues() as $key => $queue) {
            $watched[$key] ??= new SupervisedQueue(
                new DispatchedQueue($queue['connection'], $queue['queue']),
                QueueConfiguration::fromConfig($queue['connection'], $queue['queue'])->sla->targetSeconds,
            );
        }

        return array_values($watched);
    }

    private function backlog(SupervisedQueue $supervised, CarbonImmutable $now): QueueBacklog
    {
        $connection = $supervised->queue->connection;
        $queue = $supervised->queue->queue;

        try {
            $driver = $this->queues->connection($connection);
            $oldest = $driver->creationTimeOfOldestPendingJob($queue);

            return new QueueBacklog(
                connection: $connection,
                queue: $queue,
                slaSeconds: $supervised->slaSeconds,
                pending: $driver->pendingSize($queue),
                oldestWaitSeconds: is_int($oldest) ? max(0, $now->getTimestamp() - $oldest) : null,
            );
        } catch (Throwable $e) {
            return new QueueBacklog($connection, $queue, $supervised->slaSeconds, unreadable: $e->getMessage());
        }
    }
}
