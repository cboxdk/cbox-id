<?php

declare(strict_types=1);

use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Devices\Testing\FakePushTransport;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class, InteractsWithTenancy::class);

/**
 * The retry sweep runs from the scheduler, and a scheduler process has no ambient
 * environment — EnvironmentContext is a `scoped` binding that starts null and is only
 * ever populated by the HTTP middlewares or by a job re-entering its own environment.
 *
 * Every model in this module is EnvironmentOwned with a deny-by-default scope, so a
 * sweep that queries them directly compiles to `WHERE 1 = 0` and silently does nothing
 * in production while passing every test that happens to set an environment first.
 *
 * These specs pin the sweep's behaviour with NO environment in context — the way it
 * actually runs.
 */
function sweepDevice(string $environmentId, string $subjectId = 'user_1'): Device
{
    $device = new Device;
    $device->fill([
        'environment_id' => $environmentId,
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => 'Sweep iPhone',
        'status' => DeviceStatus::Active,
    ]);
    $device->save();
    $device->token_encrypted = app(SecretBox::class)->seal('fcm-token', $device->secretContext());
    $device->save();

    return $device;
}

it('rescues stranded notifications with no environment in context', function (): void {
    Queue::fake();
    app()->instance(PushTransport::class, new FakePushTransport);

    $this->actingAsEnvironment('env_a');
    sweepDevice('env_a');
    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::SecurityAlert, new PushPayload('t', 'b'));

    // The job never ran; the row is now old enough to count as stranded.
    Carbon::setTestNow(Carbon::now()->addSeconds(1000));

    // This is the state the scheduler is actually in.
    app(EnvironmentContext::class)->set(null);

    expect(app(PushDispatcher::class)->retryPending(50))->toBe(1);

    Carbon::setTestNow();
});

it('sweeps across every environment, not just one', function (): void {
    Queue::fake();
    app()->instance(PushTransport::class, new FakePushTransport);

    foreach (['env_a', 'env_b', 'env_c'] as $environment) {
        $this->actingAsEnvironment($environment);
        sweepDevice($environment);
        app(PushDispatcher::class)->dispatch('user_1', NotificationKind::SecurityAlert, new PushPayload('t', 'b'));
    }

    Carbon::setTestNow(Carbon::now()->addSeconds(1000));
    app(EnvironmentContext::class)->set(null);

    // One tenant's backlog must not be invisible because another tenant happened to be
    // in context when the scheduler booted.
    expect(app(PushDispatcher::class)->retryPending(50))->toBe(3);

    Carbon::setTestNow();
});

it('terminalises orphans with no environment in context', function (): void {
    Queue::fake();
    app()->instance(PushTransport::class, new FakePushTransport);

    $this->actingAsEnvironment('env_a');
    $device = sweepDevice('env_a');
    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::SecurityAlert, new PushPayload('t', 'b'));
    $device->delete();

    app(EnvironmentContext::class)->set(null);

    app(PushDispatcher::class)->retryPending(50);

    $this->actingAsEnvironment('env_a');

    expect(PushNotification::query()->firstOrFail()->status->isTerminal())->toBeTrue();
});
