<?php

declare(strict_types=1);

use App\Platform\Health\EventRelayHealthCheck;
use App\Platform\Health\SchedulerDoctorCheck;
use App\Platform\Health\SchedulerHealthCheck;
use App\Platform\Health\SchedulerHeartbeat;
use Cbox\Id\Console\ValueObjects\HealthResult;
use Cbox\Id\Kernel\Events\Contracts\RelayBacklog;
use Cbox\Id\Kernel\Events\ValueObjects\BacklogDepth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The scheduler: that it is declared, that it beats, and that its absence is loud.
|--------------------------------------------------------------------------
|
| Every background duty — the event relay behind webhooks, outbound SCIM, the audit-stream
| pump, pruning — is a scheduled command. The deployment manifest declared no scheduler
| process, so none of it ran, and every health signal was green. These are the tests that
| would have been red.
*/

/** `/health/status`: [status code, the named operations check]. */
function statusCheck(string $name): array
{
    config(['health.security.token' => 'probe-token', 'health.cache.enabled' => false]);

    $response = test()->getJson('/health/status?token=probe-token');
    $check = $response->json("operations.checks.{$name}");

    expect($check)->toBeArray("/health/status does not run the {$name} check at all");

    return [$response->status(), $check];
}

/** Bind a relay backlog whose oldest undelivered event is $ageSeconds old. */
function relayBehindBy(int $ageSeconds, int $waiting = 3): void
{
    app()->instance(RelayBacklog::class, new readonly class($ageSeconds, $waiting) implements RelayBacklog
    {
        public function __construct(private int $age, private int $waiting) {}

        public function depth(): BacklogDepth
        {
            return new BacklogDepth($this->waiting, 0, $this->age > 0 ? Carbon::now()->subSeconds($this->age) : null);
        }
    });
}

it('declares a scheduler process in the local manifest, as production runs one', function (): void {
    $manifest = (string) file_get_contents(base_path('cbox.yaml'));

    expect($manifest)->toMatch('/^\s+scheduler:\s*\["php",\s*"artisan",\s*"schedule:work"\]/m');
});

it('schedules every background duty the platform depends on, and its own heartbeat', function (): void {
    $events = collect(app(Schedule::class)->events());
    $commands = $events->map(fn ($event): string => (string) ($event->command ?? ''))->implode("\n");
    $names = $events->map(fn ($event): string => (string) $event->description)->all();

    expect($commands)->toContain('cbox-id:events:relay')
        ->and($commands)->toContain('cbox-id:provisioning:drain')
        ->and($commands)->toContain('cbox-id:audit-streams:pump')
        ->and($commands)->toContain('model:prune')
        ->and($names)->toContain('health:scheduler-heartbeat');
});

it('turns /health/status red when the scheduler has never beaten', function (): void {
    [$status, $check] = statusCheck('scheduler');

    expect($status)->toBe(503)
        ->and($check['status'])->toBe('critical')
        ->and($check['message'])->toContain('schedule:work');
})->group('security');

it('turns /health/status red when the last beat is older than the limit', function (): void {
    SchedulerHeartbeat::beat();
    $this->travel(SchedulerHeartbeat::maxAgeSeconds() + 60)->seconds();

    [, $check] = statusCheck('scheduler');

    expect($check['status'])->toBe('critical')
        ->and($check['metadata']['age_seconds'])->toBeGreaterThan(SchedulerHeartbeat::maxAgeSeconds());
});

it('reads a fresh beat as a running scheduler', function (): void {
    SchedulerHeartbeat::beat();

    expect(app(SchedulerHealthCheck::class)->run()->status->value)->toBe('ok');
});

it('writes the beat when the scheduled heartbeat runs', function (): void {
    expect(SchedulerHeartbeat::ageSeconds())->toBeNull();

    $this->artisan('schedule:run')->assertSuccessful();

    expect(SchedulerHeartbeat::ageSeconds())->toBe(0);
});

it('turns /health/status red when the oldest undelivered event is too old', function (): void {
    SchedulerHeartbeat::beat();
    relayBehindBy(EventRelayHealthCheck::maxLagSeconds() + 1);

    [$status, $check] = statusCheck('event_relay');

    expect($status)->toBe(503)
        ->and($check['status'])->toBe('critical')
        ->and($check['metadata']['waiting'])->toBe(3);
});

it('keeps the relay green while the backlog is moving', function (): void {
    relayBehindBy(30);

    expect(app(EventRelayHealthCheck::class)->run()->status->value)->toBe('ok');
});

it('keeps readiness free of the scheduler and the relay', function (): void {
    expect(config('health.checks.readiness'))->not->toContain(SchedulerHealthCheck::class)
        ->and(config('health.checks.readiness'))->not->toContain(EventRelayHealthCheck::class)
        ->and(config('health.checks.liveness'))->not->toContain(SchedulerHealthCheck::class);
});

it('fails the doctor in production when the scheduler is missing, and only warns elsewhere', function (): void {
    relayBehindBy(0, waiting: 0);

    $outcome = fn (): HealthResult => app(SchedulerDoctorCheck::class)->run()[0];

    expect($outcome()->label)->toBe('Scheduler not running')
        ->and($outcome()->status->value)->toBe('warn');

    app()->detectEnvironment(fn (): string => 'production');

    expect($outcome()->status->value)->toBe('fail');
});
