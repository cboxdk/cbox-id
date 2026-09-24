<?php

declare(strict_types=1);

namespace App\Platform\Queues;

use Carbon\CarbonImmutable;

/**
 * The queue workers' state in one read: is a manager alive, and is any queue waiting
 * longer than it may.
 */
readonly class QueueHealthReport
{
    /**
     * @param  list<QueueBacklog>  $queues
     */
    public function __construct(
        public ManagerStatus $manager,
        public array $queues,
        public CarbonImmutable $checkedAt,
    ) {}

    public function healthy(): bool
    {
        if (! $this->manager->healthy()) {
            return false;
        }

        foreach ($this->queues as $queue) {
            if (! $queue->healthy()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every reason this report is red, as one sentence each — empty when it is green.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->manager->state === ManagerState::Missing) {
            $since = $this->manager->secondsSinceBeat($this->checkedAt);

            $problems[] = $since === null
                ? 'No queue manager has ever reported in — start one with php artisan queue:autoscale.'
                : "No queue manager has reported in for {$since}s (limit {$this->manager->staleAfterSeconds}s) — the manager is stopped or stuck.";
        }

        foreach ($this->queues as $queue) {
            if ($queue->unreadable !== null) {
                $problems[] = "{$queue->connection}:{$queue->queue} could not be read: {$queue->unreadable}";
            } elseif ($queue->breached()) {
                $problems[] = "{$queue->connection}:{$queue->queue} — the oldest job has waited {$queue->oldestWaitSeconds}s, over its {$queue->slaSeconds}s pickup SLA ({$queue->pending} waiting).";
            }
        }

        return $problems;
    }

    /**
     * The report at the serialization edge — the health status response and the console page.
     * Counts, ages and names only; never a job's contents.
     *
     * @return array{healthy: bool, manager: array{state: string, last_beat_at: string|null, seconds_since_beat: int|null, stale_after_seconds: int, host: string|null}, queues: list<array{connection: string, queue: string, pending: int, oldest_wait_seconds: int|null, sla_seconds: int, breached: bool, unreadable: string|null}>, problems: list<string>}
     */
    public function toArray(): array
    {
        return [
            'healthy' => $this->healthy(),
            'manager' => [
                'state' => $this->manager->state->value,
                'last_beat_at' => $this->manager->lastBeat?->at->toIso8601String(),
                'seconds_since_beat' => $this->manager->secondsSinceBeat($this->checkedAt),
                'stale_after_seconds' => $this->manager->staleAfterSeconds,
                'host' => $this->manager->lastBeat?->host,
            ],
            'queues' => array_map(static fn (QueueBacklog $queue): array => [
                'connection' => $queue->connection,
                'queue' => $queue->queue,
                'pending' => $queue->pending,
                'oldest_wait_seconds' => $queue->oldestWaitSeconds,
                'sla_seconds' => $queue->slaSeconds,
                'breached' => $queue->breached(),
                'unreadable' => $queue->unreadable,
            ], $this->queues),
            'problems' => $this->problems(),
        ];
    }
}
