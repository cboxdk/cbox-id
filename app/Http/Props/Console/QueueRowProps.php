<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Platform\Queues\QueueBacklog;

/**
 * One supervised queue on Platform › Queues. Counts and ages, never a job's contents.
 */
final readonly class QueueRowProps implements Prop
{
    public function __construct(
        public string $connection,
        public string $queue,
        public int $pending,
        public ?int $oldestWaitSeconds,
        public int $slaSeconds,
        /** `ok`, `behind` (oldest job past its SLA) or `unreadable`. */
        public string $status,
        public ?string $unreadable,
    ) {}

    public static function from(QueueBacklog $backlog): self
    {
        return new self(
            connection: $backlog->connection,
            queue: $backlog->queue,
            pending: $backlog->pending,
            oldestWaitSeconds: $backlog->oldestWaitSeconds,
            slaSeconds: $backlog->slaSeconds,
            status: match (true) {
                $backlog->unreadable !== null => 'unreadable',
                $backlog->breached() => 'behind',
                default => 'ok',
            },
            unreadable: $backlog->unreadable,
        );
    }

    /**
     * @return array{connection: string, queue: string, pending: int, oldestWaitSeconds: int|null, slaSeconds: int, status: string, unreadable: string|null}
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'pending' => $this->pending,
            'oldestWaitSeconds' => $this->oldestWaitSeconds,
            'slaSeconds' => $this->slaSeconds,
            'status' => $this->status,
            'unreadable' => $this->unreadable,
        ];
    }
}
