<?php

declare(strict_types=1);

use App\Platform\Queues\DispatchedQueue;
use App\Platform\Queues\DispatchedQueues;
use App\Platform\Queues\WorkerProfile;

/**
 * THE WORKERS FOLLOW THE JOBS.
 *
 * The autoscaler gives a queue it was not told about a floor of zero workers, so a queue
 * this application dispatches to that is missing from the autoscale config waits for a
 * cold start on every job — or, on a deployment that moved webhooks to a queue of their
 * own, keeps a worker on the old name while the new one is served by nobody.
 */
function productionDefaults(): array
{
    return ['redis' => 'default', 'database' => 'default', 'sqs' => 'default', 'beanstalkd' => 'default'];
}

it('supervises the default queue of the default connection when nothing is moved', function (): void {
    $dispatched = DispatchedQueues::resolve('redis', productionDefaults(), [
        [null, null], [null, ''], [' ', null],
    ]);

    expect(array_map(fn (DispatchedQueue $queue): string => $queue->key(), $dispatched->queues))->toBe(['redis:default'])
        ->and($dispatched->autoscaleGroups(WorkerProfile::class))->toBe([
            'cbox-id-redis' => [
                'connection' => 'redis',
                'queues' => ['default'],
                'mode' => 'priority',
                'profile' => WorkerProfile::class,
            ],
        ]);
});

it('follows a dispatcher moved to a queue of its own, keeping the default queue first', function (): void {
    $groups = DispatchedQueues::resolve('redis', productionDefaults(), [
        [null, 'webhooks'],
        [null, 'push'],
        [null, 'webhooks'], // a second dispatcher on the same queue is one queue, once
    ])->autoscaleGroups(WorkerProfile::class);

    expect($groups['cbox-id-redis']['queues'])->toBe(['default', 'webhooks', 'push']);
});

it('gives a dispatcher on another connection a worker group of its own', function (): void {
    // A queue named on a different connection is a different queue: a worker polling
    // `redis` never sees a job pushed to the `database` connection.
    $groups = DispatchedQueues::resolve('redis', ['redis' => 'default', 'database' => 'jobs-default'], [
        ['database', null],
    ])->autoscaleGroups(WorkerProfile::class);

    expect(array_keys($groups))->toBe(['cbox-id-redis', 'cbox-id-database'])
        // …on that connection's own default queue, which is where `onQueue(null)` sends it.
        ->and($groups['cbox-id-database']['queues'])->toBe(['jobs-default']);
});

it('supervises nothing on a connection that runs jobs inline', function (): void {
    // `sync` runs the job inside the request that queued it; a worker for it would poll
    // nothing forever. The test suite runs this way, and so do some self-hosted installs.
    expect(DispatchedQueues::resolve('sync', productionDefaults(), [[null, 'webhooks']])->queues)->toBe([])
        ->and(DispatchedQueues::resolve('redis', productionDefaults(), [['null', 'x'], ['deferred', 'y']])->autoscaleGroups(WorkerProfile::class))
        ->toBe(['cbox-id-redis' => ['connection' => 'redis', 'queues' => ['default'], 'mode' => 'priority', 'profile' => WorkerProfile::class]]);
});
