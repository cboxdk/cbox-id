<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Platform\Queues\QueueHealthReport;

/**
 * Platform › Queues: whether a queue manager is running, and when it last said so.
 */
final readonly class QueueManagerProps implements Prop
{
    public function __construct(
        public string $state,
        public ?string $lastBeatAt,
        public ?int $secondsSinceBeat,
        public ?string $host,
        public int $staleAfterSeconds,
    ) {}

    public static function from(QueueHealthReport $report): self
    {
        return new self(
            state: $report->manager->state->value,
            lastBeatAt: $report->manager->lastBeat?->at->toIso8601String(),
            secondsSinceBeat: $report->manager->secondsSinceBeat($report->checkedAt),
            host: $report->manager->lastBeat?->host,
            staleAfterSeconds: $report->manager->staleAfterSeconds,
        );
    }

    /**
     * @return array{state: string, lastBeatAt: string|null, secondsSinceBeat: int|null, host: string|null, staleAfterSeconds: int}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'lastBeatAt' => $this->lastBeatAt,
            'secondsSinceBeat' => $this->secondsSinceBeat,
            'host' => $this->host,
            'staleAfterSeconds' => $this->staleAfterSeconds,
        ];
    }
}
