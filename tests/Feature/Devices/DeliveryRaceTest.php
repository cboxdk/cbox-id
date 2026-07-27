<?php

declare(strict_types=1);

use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Devices\Support\DeviceCircuitBreaker;
use Cbox\Id\Devices\Testing\FakePushTransport;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Cbox\Id\Devices\ValueObjects\PushResult;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A push fans out per device and is delivered by workers, so two of them can be acting
 * on the same device row at once — and the app can be re-enrolling underneath both.
 * These specs pin the interleavings that lose data.
 *
 * Helpers are local rather than borrowed from PushDeliveryTest: Pest file-scope
 * functions are global, so relying on another spec file's definitions only works while
 * the whole suite happens to be loaded, and breaks the moment one file is run alone.
 */
function racingDevice(string $subjectId = 'user_1'): Device
{
    $device = new Device;
    $device->fill([
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => 'Racing iPhone',
        'status' => DeviceStatus::Active,
    ]);
    $device->save();

    $device->token_encrypted = app(SecretBox::class)->seal('fcm-token', $device->secretContext());
    $device->save();

    return $device;
}

function racingPayload(): PushPayload
{
    return new PushPayload(title: 'Approval request', body: 'Open Cbox ID to review.');
}

it('does not wipe a token that was rotated while the send was in flight', function (): void {
    Queue::fake();
    app()->instance(PushTransport::class, new FakePushTransport);

    $device = racingDevice();
    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, racingPayload());
    $notification = PushNotification::query()->firstOrFail();

    // The worker is holding the device as loaded, with the OLD token...
    $stale = Device::query()->whereKey($device->id)->firstOrFail();

    // ...while the app re-enrols with a rotated one and gets a 200 back.
    $device->token_encrypted = app(SecretBox::class)->seal('rotated-token', $device->secretContext());
    $device->save();

    // FCM now answers the worker that the OLD token is dead.
    app()->instance(PushTransport::class, (new FakePushTransport)->willReturn(
        PushResult::permanentFailure('UNREGISTERED', 404),
    ));

    // Drive the attempt with the stale instance, as a worker would.
    $dispatcher = app(PushDispatcher::class);
    (fn () => $this->attempt($stale, $notification))->call($dispatcher);

    $fresh = $device->fresh();

    // The rotated token must survive: the app got a success and has no reason to retry,
    // so clearing it here would silently stop the user's prompts for good.
    expect($fresh?->status)->toBe(DeviceStatus::Active)
        ->and(app(SecretBox::class)->open((string) $fresh?->token_encrypted, $device->secretContext()))
        ->toBe('rotated-token');
});

it('still retires when the token has not moved', function (): void {
    app()->instance(PushTransport::class, (new FakePushTransport)->willReturn(
        PushResult::permanentFailure('UNREGISTERED', 404),
    ));

    $device = racingDevice();
    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, racingPayload());

    $fresh = $device->fresh();

    expect($fresh?->status)->toBe(DeviceStatus::Retired)
        ->and($fresh?->token_encrypted)->toBeNull();
});

it('accumulates concurrent failures instead of overwriting them', function (): void {
    $device = racingDevice();
    $breaker = app(DeviceCircuitBreaker::class);

    // Two workers each loaded the device before either recorded anything.
    $workerA = Device::query()->whereKey($device->id)->firstOrFail();
    $workerB = Device::query()->whereKey($device->id)->firstOrFail();

    $breaker->recordFailure($workerA, 'timeout');
    $breaker->recordFailure($workerB, 'timeout');

    // A read-modify-write on the stale in-memory value would leave this at 1.
    expect($device->fresh()?->consecutive_failures)->toBe(2);
});

it('does not let a stale success erase a concurrent failure', function (): void {
    $device = racingDevice();
    $breaker = app(DeviceCircuitBreaker::class);

    $workerA = Device::query()->whereKey($device->id)->firstOrFail();

    foreach (range(1, 5) as $ignored) {
        $breaker->recordFailure(Device::query()->whereKey($device->id)->firstOrFail(), 'timeout');
    }

    expect($device->fresh()?->circuit_opened_at)->not->toBeNull();

    // Worker A's success is genuine and does close the breaker — but it writes absolute
    // values rather than a stale count, so the outcome is deterministic either way.
    $breaker->recordSuccess($workerA);

    expect($device->fresh()?->consecutive_failures)->toBe(0)
        ->and($device->fresh()?->circuit_opened_at)->toBeNull();
});

it('does not slide the cooldown forward on every further failure', function (): void {
    $device = racingDevice();
    $breaker = app(DeviceCircuitBreaker::class);

    foreach (range(1, 5) as $ignored) {
        $breaker->recordFailure(Device::query()->whereKey($device->id)->firstOrFail(), 'timeout');
    }

    $openedAt = $device->fresh()?->circuit_opened_at;

    $breaker->recordFailure(Device::query()->whereKey($device->id)->firstOrFail(), 'timeout');

    // Re-stamping would push the half-open probe further away on every failure, so the
    // breaker would never admit one.
    expect($device->fresh()?->circuit_opened_at?->timestamp)->toBe($openedAt?->timestamp);
});
