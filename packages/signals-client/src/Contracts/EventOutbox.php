<?php

declare(strict_types=1);

namespace Cbox\Signals\Contracts;

use Cbox\Signals\Data\EventBatch;

interface EventOutbox
{
    /** Call inside the application's database transaction to commit business data and events together. */
    public function enqueue(EventBatch $batch, string $idempotencyKey): void;

    /** Send at most one due batch. Returns false when nothing is due. */
    public function dispatch(): bool;
}
