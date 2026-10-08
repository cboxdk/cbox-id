<?php

declare(strict_types=1);

use App\Platform\Health\QueueWorkersDoctorCheck;
use App\Platform\Health\QueueWorkersHealthCheck;
use App\Platform\Health\SchedulerHeartbeat;
use App\Platform\Queues\CacheManagerHeartbeat;
use App\Platform\Queues\Contracts\ManagerHeartbeat;
use App\Platform\Queues\Contracts\QueueHealth;
use App\Platform\Queues\DispatchedQueues;
use App\Platform\Queues\ManagerBeat;
use App\Platform\Queues\ManagerState;
use App\Platform\Queues\WorkerProfile;
use Cbox\Id\Console\ValueObjects\HealthResult;
use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\GroupConfiguration;
use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;
use Cbox\LaravelQueueAutoscale\Events\AutoscaleManagerStarted;
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Queue workers — the manager's configuration, its heartbeat, and the health signal.
|--------------------------------------------------------------------------
|
| Production ran with no queue worker at all for weeks: webhooks, back-channel logout and
| manifest syncs were queued and never sent, and every health signal was green because
| none of them looked at the queue. These are the tests that would have been red.
*/

/**
 * Evaluate config/queue-autoscale.php the way production does: QUEUE_CONNECTION=redis,
 * plus whatever else the test sets. Every variable is put back the way it was — unset
 * where it was unset — so one test's environment never leaks into the next.
 *
 * @param  array<string, string>  $env
 */
function productionAutoscaleConfig(array $env = []): array
{
    $env = ['QUEUE_CONNECTION' => 'redis'] + $env;
    $previous = [];

    foreach ($env as $name => $value) {
        $previous[$name] = getenv($name);

        putenv($name.'='.$value);
        $_SERVER[$name] = $_ENV[$name] = $value;
    }

    try {
        return require config_path('queue-autoscale.php');
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                putenv($name.'='.$value);
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
    }
}

/** Supervise the `database` connection's default queue — a real driver the suite has. */
function superviseDatabaseQueue(): void
{
    config([
        'queue-autoscale.enabled' => true,
        'queue-autoscale.queues' => [],
        'queue-autoscale.groups' => DispatchedQueues::resolve('database', ['database' => 'default'], [])
            ->autoscaleGroups(WorkerProfile::class),
    ]);
}

/** A job waiting on the database queue since $seconds ago. */
function jobWaitingFor(int $seconds): void
{
    test()->travel(-$seconds)->seconds();
    Queue::connection('database')->pushRaw((string) json_encode(['displayName' => 'Probe', 'job' => 'none', 'data' => []]), 'default');
    test()->travelBack();
}

/** `/health/status`, where the queue check lives: [status code, the queue_workers check]. */
function healthStatus(): array
{
    config(['health.security.token' => 'probe-token', 'health.cache.enabled' => false]);

    // The scheduler shares this endpoint; a live one keeps the status code about the queue.
    SchedulerHeartbeat::beat();

    $response = test()->getJson('/health/status?token=probe-token');
    $check = $response->json('operations.checks.queue_workers');

    expect($check)->toBeArray('/health/status does not run the queue_workers check at all');

    return [$response->status(), $check];
}

it('gives every queue the app dispatches to a floor of one worker in production', function (): void {
    config(['queue-autoscale' => productionAutoscaleConfig()]);

    $groups = GroupConfiguration::allFromConfig();

    // The queue every framework job, logout token and webhook lands on by default.
    expect($groups)->toHaveKey('cbox-id-redis')
        ->and($groups['cbox-id-redis']->connection)->toBe('redis')
        ->and($groups['cbox-id-redis']->queues)->toBe(['default'])
        ->and($groups['cbox-id-redis']->workers->min)->toBeGreaterThanOrEqual(1)
        // Named by the literal connection, never the package's `default` placeholder,
        // which is no connection at all and would spawn `queue:work default`.
        ->and(AutoscaleConfiguration::configuredQueues())->toBe([]);
});

it('cannot outgrow a 512 MB instance or hand a running job to a second worker', function (): void {
    config(['queue-autoscale' => productionAutoscaleConfig()]);

    $group = GroupConfiguration::allFromConfig()['cbox-id-redis'];

    expect(AutoscaleConfiguration::maxTotalWorkers())->toBe(2)
        ->and($group->workers->max)->toBeLessThanOrEqual(2)
        ->and(AutoscaleConfiguration::maxMemoryPercent())->toBeLessThanOrEqual(70)
        // A job may not outlive `retry_after`, or Redis re-delivers it to a second worker
        // while the first is still sending it: a webhook or logout token sent twice.
        ->and($group->workers->timeoutSeconds)->toBeLessThan((int) config('queue.connections.redis.retry_after'))
        ->and($group->workers->timeoutSeconds)->toBeLessThan((int) config('queue.connections.database.retry_after'))
        // The fuse holds a failing relying party at the floor instead of adding workers.
        ->and(AutoscaleConfiguration::fuseEnabled())->toBeTrue()
        ->and($group->fuse->enabled)->toBeTrue()
        // One App instance, one manager.
        ->and(AutoscaleConfiguration::clusterEnabled())->toBeFalse()
        ->and($group->sla->targetSeconds)->toBe(WorkerProfile::SLA_SECONDS);
});

/*
 * THE WORKER COUNT IS A SETTING, AND RAISING IT DOES SOMETHING.
 *
 * Every queue sits in one group, so the group's `max` decides how many workers run — the
 * host-wide cap only clamps it. When that `max` was a literal in the profile, raising
 * QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS on a queue pod with memory to spare changed nothing.
 */
it('keeps today\'s bounds when no worker setting is given', function (): void {
    config(['queue-autoscale' => productionAutoscaleConfig()]);

    $group = GroupConfiguration::allFromConfig()['cbox-id-redis'];

    expect($group->workers->min)->toBe(WorkerProfile::MIN_WORKERS)
        ->and($group->workers->max)->toBe(WorkerProfile::MAX_WORKERS)
        ->and(AutoscaleConfiguration::maxTotalWorkers())->toBe(2);
});

it('runs as many workers as the environment allows, raising both ceilings together', function (): void {
    config(['queue-autoscale' => productionAutoscaleConfig([
        'QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS' => '6',
        'QUEUE_AUTOSCALE_WORKERS_MIN' => '2',
        'QUEUE_AUTOSCALE_WORKERS_MAX' => '6',
    ])]);

    $group = GroupConfiguration::allFromConfig()['cbox-id-redis'];

    expect($group->workers->min)->toBe(2)
        ->and($group->workers->max)->toBe(6)
        ->and(AutoscaleConfiguration::maxTotalWorkers())->toBe(6)
        // Only the bounds move: every other number is still the profile's.
        ->and($group->workers->timeoutSeconds)->toBe(WorkerProfile::JOB_TIMEOUT_SECONDS)
        ->and($group->sla->targetSeconds)->toBe(WorkerProfile::SLA_SECONDS)
        ->and($group->fuse->enabled)->toBeTrue();
});

it('refuses to load a group maximum the total cap would silently clamp', function (): void {
    expect(fn (): array => productionAutoscaleConfig(['QUEUE_AUTOSCALE_WORKERS_MAX' => '4']))
        ->toThrow(InvalidConfigurationException::class, 'QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS (2)');
});

it('refuses to load a group maximum below its minimum', function (): void {
    expect(fn (): array => productionAutoscaleConfig([
        'QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS' => '6',
        'QUEUE_AUTOSCALE_WORKERS_MIN' => '3',
        'QUEUE_AUTOSCALE_WORKERS_MAX' => '2',
    ]))->toThrow(InvalidConfigurationException::class, 'must be at least QUEUE_AUTOSCALE_WORKERS_MIN (3)');
});

it('records a heartbeat from the manager\'s own events', function (): void {
    expect(app(ManagerHeartbeat::class)->last())->toBeNull();

    event(new AutoscaleManagerStarted('mgr-1', 'app-host', false, '', 5, 0, '4.3.1'));

    expect(app(ManagerHeartbeat::class)->last())
        ->toBeInstanceOf(ManagerBeat::class)
        ->and(app(ManagerHeartbeat::class)->last()?->host)->toBe('app-host');

    $this->travel(10)->minutes();

    event(new ScalingDecisionMade(new ScalingDecision('redis', 'default', 1, 1, 'hold')));

    expect(app(ManagerHeartbeat::class)->last()?->at->isSameMinute(now()))->toBeTrue();
});

it('never lets a heartbeat failure reach the manager loop', function (): void {
    // A beat is an observation; a cache outage must not become an evaluation failure.
    app()->instance(ManagerHeartbeat::class, new class implements ManagerHeartbeat
    {
        public function beat(string $managerId, string $host): void
        {
            throw new RuntimeException('cache is down');
        }

        public function last(): ?ManagerBeat
        {
            return null;
        }
    });

    event(new ScalingDecisionMade(new ScalingDecision('redis', 'default', 1, 1, 'hold')));
})->throwsNoExceptions();

it('reads an unrecognisable cache value as no beat, never as a live manager', function (): void {
    Cache::put(CacheManagerHeartbeat::KEY, 'alive', 60);

    expect(app(ManagerHeartbeat::class)->last())->toBeNull();
});

it('turns /health/status red when no queue manager has ever run', function (): void {
    superviseDatabaseQueue();

    [$status, $check] = healthStatus();

    expect($status)->toBe(503)
        ->and($check['status'])->toBe('critical')
        ->and($check['message'])->toContain('No queue manager has ever reported in')
        ->and($check['metadata']['manager']['state'])->toBe(ManagerState::Missing->value);
})->group('security');

/*
 * READINESS NEVER CARRIES THE QUEUE.
 *
 * `/health/ready` is what the platform ROUTES on — on Kubernetes it is the id Deployment's
 * readinessProbe. The queue manager is a separate process (a separate pod there); if its
 * death turned readiness red, every web instance would be pulled out of the load balancer
 * at once and the site would go down because a background process stopped. So the same
 * dead manager has to read red where people are told and green where traffic is moved.
 */
it('keeps readiness green with no manager alive while /health/status reports it', function (): void {
    superviseDatabaseQueue();
    jobWaitingFor(600);
    config(['health.security.token' => 'probe-token', 'health.cache.enabled' => false]);

    $ready = $this->getJson('/health/ready?token=probe-token');

    $ready->assertOk();
    expect(array_keys((array) $ready->json('checks')))->not->toContain('queue_workers')
        ->and(config('health.checks.readiness'))->not->toContain(QueueWorkersHealthCheck::class)
        ->and(config('health.checks.liveness'))->not->toContain(QueueWorkersHealthCheck::class);

    $this->getJson('/up')->assertOk();

    $status = $this->getJson('/health/status?token=probe-token');

    $status->assertStatus(503);
    expect($status->json('status'))->toBe('critical')
        ->and($status->json('readiness.status'))->toBe('ok')
        ->and($status->json('operations.checks.queue_workers.status'))->toBe('critical')
        ->and($status->json('operations.checks.queue_workers.message'))->toContain('No queue manager has ever reported in');
})->group('security');

it('keeps /health/status behind the health token', function (): void {
    config(['health.security.token' => 'probe-token', 'health.cache.enabled' => false]);

    $this->getJson('/health/status')->assertForbidden();
    $this->getJson('/health/status?token=wrong')->assertForbidden();
})->group('security');

it('is green with a live manager and nothing waiting', function (): void {
    superviseDatabaseQueue();
    app(ManagerHeartbeat::class)->beat('mgr-1', 'app-host');

    [$status, $check] = healthStatus();

    expect($status)->toBe(200)
        ->and($check['status'])->toBe('ok')
        ->and($check['metadata']['queues'][0]['queue'])->toBe('default');
});

it('turns red when the manager goes silent for longer than a held scale-down can explain', function (): void {
    superviseDatabaseQueue();
    app(ManagerHeartbeat::class)->beat('mgr-1', 'app-host');

    $stale = app(QueueHealth::class)->inspect()->manager->staleAfterSeconds;

    // Two cooldowns and four intervals: a manager holding a scale-down says nothing for up
    // to one cooldown, and must not read as dead while it does.
    expect($stale)->toBe(2 * 60 + 4 * 5);

    $this->travel($stale - 1)->seconds();
    expect(app(QueueHealth::class)->inspect()->healthy())->toBeTrue();

    $this->travel(2)->seconds();

    [$status, $check] = healthStatus();

    expect($status)->toBe(503)
        ->and($check['message'])->toContain('No queue manager has reported in for');
});

it('turns red when the oldest job has waited past its pickup SLA, even with a manager running', function (): void {
    superviseDatabaseQueue();
    app(ManagerHeartbeat::class)->beat('mgr-1', 'app-host');

    // Inside the SLA: a queue with work in it is not a problem.
    jobWaitingFor(WorkerProfile::SLA_SECONDS - 5);
    expect(healthStatus()[0])->toBe(200);

    // Past it: the workers are not getting through, whatever the manager says.
    jobWaitingFor(WorkerProfile::SLA_SECONDS + 90);

    [$status, $check] = healthStatus();

    expect($status)->toBe(503)
        ->and($check['message'])->toContain('database:default — the oldest job has waited')
        ->and($check['message'])->toContain('over its 30s pickup SLA (2 waiting)')
        ->and($check['metadata']['queues'][0]['breached'])->toBeTrue();
});

it('still judges the backlog when the autoscaler is off and plain workers run instead', function (): void {
    superviseDatabaseQueue();
    config(['queue-autoscale.enabled' => false]);

    $report = app(QueueHealth::class)->inspect();

    expect($report->manager->state)->toBe(ManagerState::NotSupervised)
        ->and($report->healthy())->toBeTrue();

    jobWaitingFor(600);

    expect(app(QueueHealth::class)->inspect()->healthy())->toBeFalse();
});

it('supervises nothing when every job runs inline', function (): void {
    // The suite's own shape: QUEUE_CONNECTION=sync, so the derived groups are empty and
    // no manager is expected.
    $report = app(QueueHealth::class)->inspect();

    expect($report->queues)->toBe([])
        ->and($report->manager->state)->toBe(ManagerState::NotSupervised)
        ->and($report->healthy())->toBeTrue();
});

it('tells the doctor, in words, that no manager is running', function (): void {
    superviseDatabaseQueue();

    $results = app(QueueWorkersDoctorCheck::class)->run();
    $labels = array_map(fn (HealthResult $result): string => $result->label, $results);

    // This PHP is the one the suite runs on, and the manager needs the same extensions.
    expect($labels)->toContain('Queue manager can run on this PHP')
        ->and($labels)->toContain('No queue manager running');
});
