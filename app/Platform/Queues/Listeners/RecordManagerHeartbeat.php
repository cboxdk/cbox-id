<?php

declare(strict_types=1);

namespace App\Platform\Queues\Listeners;

use App\Platform\Queues\Contracts\ManagerHeartbeat;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStarted;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Events\WorkersScaled;
use Throwable;

/**
 * Turns the manager's own events into the heartbeat the web tier can read.
 *
 * `ScalingDecisionMade` is announced once per workload per evaluation cycle — every five
 * seconds — which is the closest thing the manager has to a pulse. The start event beats
 * too, so a manager is visible from its first second rather than its first cycle.
 *
 * NEVER THROWS. These run inside the manager's loop, and a cache blip must not become an
 * evaluation failure: the heartbeat is an observation of the manager, and an observation
 * that can take down the thing it observes is worse than none. A beat that was not
 * recorded shows up where it should — as a manager that looks silent.
 */
class RecordManagerHeartbeat
{
    public function __construct(private readonly ManagerHeartbeat $heartbeat) {}

    public function handle(AutoscaleManagerStarted|ScalingDecisionMade|WorkersScaled $event): void
    {
        [$managerId, $host] = $event instanceof AutoscaleManagerStarted
            ? [$event->managerId, $event->host]
            : [AutoscaleConfiguration::managerId(), AutoscaleConfiguration::hostLabel()];

        try {
            $this->heartbeat->beat($managerId, $host);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
