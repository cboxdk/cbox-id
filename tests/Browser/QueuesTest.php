<?php

declare(strict_types=1);

use App\Platform\Queues\DispatchedQueues;
use App\Platform\Queues\WorkerProfile;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Platform › Queues and the job monitor, as a browser draws them.
|--------------------------------------------------------------------------
|
| The request tests prove who is let in and what the server sends. What they cannot see is
| whether the red state is actually drawn, and whether the monitor — a vendor page running
| Alpine.js under a policy of its own — does anything at all once it loads: under the
| console's CSP it arrives as a 200 and renders as a dead page.
*/

it('draws a stopped manager and a queue that is behind', function (): void {
    actAsOperator('queues-browser@platform.test');

    config([
        'queue-autoscale.enabled' => true,
        'queue-autoscale.groups' => DispatchedQueues::resolve('database', ['database' => 'default'], [])
            ->autoscaleGroups(WorkerProfile::class),
    ]);

    $this->travel(-5)->minutes();
    Queue::connection('database')->pushRaw((string) json_encode(['displayName' => 'Probe', 'job' => 'none', 'data' => []]), 'default');
    $this->travelBack();

    visit('/platform/queues')
        ->assertSee('Queues')
        ->assertSee('Not running')
        ->assertSee('Needs attention')
        ->assertSee('Behind')
        ->assertSee('Open job monitor')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'platform-queues-behind');
})->group('a11y');

it('runs the job monitor under its own policy', function (): void {
    actAsOperator('monitor-browser@platform.test');

    visit('/platform/queues/monitor')
        ->assertSee('Queue Monitor')
        // Alpine initialised — it strips `x-cloak` from everything it mounted. Under the
        // console's policy it cannot compile a single attribute and every one stays.
        ->assertScript('document.querySelectorAll("[x-cloak]").length', 0)
        ->assertScript('typeof window.Alpine', 'object')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'platform-queue-monitor');
});
