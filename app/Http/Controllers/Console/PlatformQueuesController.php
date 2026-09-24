<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Console\QueueManagerProps;
use App\Http\Props\Console\QueueRowProps;
use App\Http\Props\Shared\HelpProps;
use App\Platform\Help\HelpTopic;
use App\Platform\Queues\Contracts\QueueHealth;
use App\Platform\Queues\QueueBacklog;
use Illuminate\Support\Facades\Route;
use Inertia\Response;

/**
 * PLATFORM › QUEUES — is the background work of this install actually being done?
 *
 * The same report the health status check and the doctor read ({@see QueueHealth}), so the
 * three cannot disagree, rendered for a person: is a manager running, and is any queue
 * behind. The job-by-job view is the queue monitor, a separate page this one links to —
 * it is the package's own dashboard, not an Inertia page, so it is opened with a full
 * navigation rather than drawn inside the console.
 */
final readonly class PlatformQueuesController extends ConsoleController
{
    public function __invoke(QueueHealth $health): Response
    {
        // 404, not 403, like every Platform page: the console does not confirm to a
        // stranger that this deployment has a staff area at that address.
        abort_unless($this->scope->isPlatformOperator(), 404);

        $report = $health->inspect();

        return $this->page('console/platform/queues', 'Queues', [
            'help' => HelpProps::for(HelpTopic::PlatformQueues),
            'healthy' => $report->healthy(),
            'manager' => QueueManagerProps::from($report),
            'queues' => array_map(static fn (QueueBacklog $queue): QueueRowProps => QueueRowProps::from($queue), $report->queues),
            'problems' => $report->problems(),
            // Absent when the monitor is switched off, so the page offers no dead link.
            'monitorHref' => Route::has('queue-monitor.dashboard') ? route('queue-monitor.dashboard') : null,
        ]);
    }
}
