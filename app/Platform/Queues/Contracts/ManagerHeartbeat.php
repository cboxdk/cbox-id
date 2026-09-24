<?php

declare(strict_types=1);

namespace App\Platform\Queues\Contracts;

use App\Platform\Queues\ManagerBeat;

/**
 * Proof that a queue manager (`php artisan queue:autoscale`) is running somewhere.
 *
 * The manager is a separate, long-running process — on Laravel Cloud a background
 * process, self-hosted a systemd unit — and the web tier cannot see it. Without a signal
 * of its own, "no manager" and "an idle manager" look identical from here: an empty queue
 * either way. That is exactly how this deployment ran with no worker for weeks.
 *
 * Written by the manager on every evaluation cycle, read by the health status check and the
 * Platform › Queues page. It must live in a store BOTH processes share — the application
 * cache, which is Redis in production.
 */
interface ManagerHeartbeat
{
    public function beat(string $managerId, string $host): void;

    /** The most recent beat from any manager, or null if none has ever been recorded. */
    public function last(): ?ManagerBeat;
}
