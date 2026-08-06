<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Contracts;

use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use DateTimeInterface;

/**
 * Fans a notification out to a subject's enrolled devices and drives the delivery
 * lifecycle.
 *
 * The contract's central promise is that {@see dispatch()} is DURABLE BEFORE PROMPT:
 * it writes one notification row per eligible device and only then enqueues the jobs,
 * so a queue that loses the message still leaves the work visible to
 * {@see retryPending()}. Callers on a latency-critical path (CIBA) get promptness from
 * the queue; correctness comes from the rows.
 */
interface PushDispatcher
{
    /**
     * Fan out to every Active device belonging to `$subjectId` in the current
     * environment. Returns the number of notifications written.
     *
     * `$expiresAt` is a hard deadline: a notification still undelivered by then is
     * settled as Expired rather than retried, because a prompt the user cannot act on
     * is worse than no prompt. Null means no deadline.
     *
     * Never throws for delivery reasons — a subject with no devices is 0, not an error.
     */
    public function dispatch(
        string $subjectId,
        NotificationKind $kind,
        PushPayload $payload,
        ?DateTimeInterface $expiresAt = null,
    ): int;

    /**
     * Attempt one notification by id. Idempotent: a notification already in a terminal
     * state returns immediately without re-sending, so a duplicate job is harmless.
     */
    public function deliver(string $notificationId): void;

    /**
     * Re-enqueue notifications that are due for retry, plus Pending ones old enough to
     * be presumed stranded by a lost job. Returns how many were re-enqueued.
     */
    public function retryPending(int $limit): int;
}
