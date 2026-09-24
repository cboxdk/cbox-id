<?php

declare(strict_types=1);

namespace App\Platform\Health;

use App\Platform\Queues\Contracts\QueueHealth;
use App\Platform\Queues\ManagerState;
use Cbox\Id\Console\Contracts\HealthCheck;
use Cbox\Id\Console\ValueObjects\HealthResult;

/**
 * `cbox-id:doctor`'s view of the queue workers — the checks a person runs by hand.
 *
 * THE EXTENSIONS FIRST, because their absence is the one failure the manager cannot
 * report itself: `queue:autoscale` is a signal-handling daemon and needs `pcntl` and
 * `posix`. Composer refuses to INSTALL without them, but an image built on one PHP and run
 * on another, or a CLI that differs from FPM, gets past that — and then the manager dies
 * at its first signal handler, from a process supervisor's log nobody reads. The doctor
 * runs in the same CLI the manager does, so it can say so in words.
 */
class QueueWorkersDoctorCheck implements HealthCheck
{
    public function __construct(private readonly QueueHealth $health) {}

    public function run(): array
    {
        $results = [];

        $missing = array_values(array_filter(['pcntl', 'posix'], static fn (string $extension): bool => ! extension_loaded($extension)));

        $results[] = $missing === []
            ? HealthResult::ok('Queue manager can run on this PHP', 'pcntl and posix are loaded')
            : HealthResult::fail(
                'Queue manager cannot run on this PHP',
                'Missing ext-'.implode(', ext-', $missing).'. `php artisan queue:autoscale` needs both to supervise its '
                .'workers; without a manager no webhook, back-channel logout or queued mail is ever delivered.',
            );

        $report = $this->health->inspect();

        $results[] = match ($report->manager->state) {
            ManagerState::Running => HealthResult::ok('Queue manager running', 'last heard from '.($report->manager->lastBeat->host ?? 'unknown host')),
            ManagerState::NotSupervised => HealthResult::warn(
                'Queue manager not supervising anything',
                'The autoscaler is off or every queue runs inline. Something else must process the queue.',
            ),
            ManagerState::Missing => HealthResult::fail(
                'No queue manager running',
                'Run `php artisan queue:autoscale` as a long-lived process (Laravel Cloud: a background process). '
                .'Until one runs, webhooks and back-channel logout are queued and never delivered.',
            ),
        };

        foreach ($report->queues as $queue) {
            $label = "Queue {$queue->connection}:{$queue->queue}";

            $results[] = match (true) {
                $queue->unreadable !== null => HealthResult::fail($label.' could not be read', $queue->unreadable),
                $queue->breached() => HealthResult::fail(
                    $label.' is behind',
                    "The oldest job has waited {$queue->oldestWaitSeconds}s — over its {$queue->slaSeconds}s pickup SLA ({$queue->pending} waiting).",
                ),
                default => HealthResult::ok($label, "{$queue->pending} waiting, SLA {$queue->slaSeconds}s"),
            };
        }

        return $results;
    }
}
