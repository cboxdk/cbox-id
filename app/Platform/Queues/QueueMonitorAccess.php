<?php

declare(strict_types=1);

namespace App\Platform\Queues;

use App\Platform\Console\ConsoleScope;
use App\Platform\PlaneResolver;
use Illuminate\Http\Request;

/**
 * Who may open the queue monitor: a PLATFORM OPERATOR, on the PLATFORM ROOT's host.
 * Nobody else, on no other host.
 *
 * The monitor shows every job this deployment runs for every customer — class names,
 * queues, failure messages and traces — and it can delete records and resolve stuck jobs.
 * That is operator reach, so it is operator-gated, and it is asked of the same
 * {@see ConsoleScope} every Platform page asks, so the rail and the monitor cannot
 * disagree about who is staff.
 *
 * THE HOST QUESTION TOO, which the Platform pages themselves do not ask. They are
 * reachable from whichever console an operator stands in; this is not, because it is a
 * separate application with its own UI and its own routes, and serving it under a
 * customer's white-label domain would put a staff tool on somebody else's origin. It is
 * the same bulkhead `plane:operator` enforces on the route, asked again here — the route
 * middleware and this callback are two independent locks, and either one refuses.
 *
 * This is the package's `LaravelQueueMonitor::auth()` callback. Without one the package
 * falls back to "is APP_ENV local", which is a statement about a machine, not a person.
 */
class QueueMonitorAccess
{
    public function __construct(
        private readonly PlaneResolver $planes,
    ) {}

    public function allows(Request $request): bool
    {
        return $this->planes->onOperatorPlane($request->getHost())
            && app(ConsoleScope::class)->isPlatformOperator();
    }
}
