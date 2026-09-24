<?php

declare(strict_types=1);

namespace App\Platform\Queues\Contracts;

use App\Platform\Queues\QueueHealthReport;

/**
 * Whether queued work is actually being done — the one question the health status check, the
 * doctor and the Platform › Queues page all ask, answered in one place so they cannot
 * disagree.
 */
interface QueueHealth
{
    public function inspect(): QueueHealthReport;
}
