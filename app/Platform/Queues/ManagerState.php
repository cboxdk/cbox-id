<?php

declare(strict_types=1);

namespace App\Platform\Queues;

/**
 * What the health check can say about the queue manager.
 */
enum ManagerState: string
{
    /** A manager beat within the staleness window. */
    case Running = 'running';

    /** No beat within the window — never started, crashed, or stuck. Red. */
    case Missing = 'missing';

    /**
     * No manager is expected: the autoscaler is switched off (somebody runs plain
     * `queue:work` instead), or every job runs inline on a `sync` connection. The backlog
     * check still applies — dead workers of any kind show up as jobs that wait.
     */
    case NotSupervised = 'not-supervised';
}
