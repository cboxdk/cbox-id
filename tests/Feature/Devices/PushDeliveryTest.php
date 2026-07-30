<?php

declare(strict_types=1);

use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Enums\NotificationStatus;
use Cbox\Id\Devices\Jobs\DeliverPushNotification;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Devices\Testing\FakePushTransport;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Cbox\Id\Devices\ValueObjects\PushResult;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function fakeTransport(): FakePushTransport
{
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    return $transport;
}

function enrolledDevice(string $subjectId = 'user_1', string $name = 'Test iPhone'): Device
{
    $device = new Device;
    $device->fill([
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => $name,
        'status' => DeviceStatus::Active,
    ]);
    $device->save();

    $device->token_encrypted = app(SecretBox::class)->seal('fcm-token', $device->secretContext());
    $device->save();

    return $device;
}

function samplePayload(): PushPayload
{
    return new PushPayload(title: 'Approval request', body: 'Open Cbox ID to review.');
}

it('writes a durable notification row before the job is dispatched', function (): void {
    Queue::fake();
    fakeTransport();
    enrolledDevice();

    $written = app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());

    // The row exists even though no worker has run — that is what makes the stranded
    // rescue meaningful.
    expect($written)->toBe(1)
        ->and(PushNotification::query()->where('status', NotificationStatus::Pending)->count())->toBe(1);

    Queue::assertPushed(DeliverPushNotification::class);
});

it('fans out to every notifiable device and skips retired ones', function (): void {
    Queue::fake();
    fakeTransport();

    enrolledDevice('user_1', 'iPhone');
    enrolledDevice('user_1', 'iPad');

    $retired = enrolledDevice('user_1', 'Old Pixel');
    $retired->status = DeviceStatus::Retired;
    $retired->save();

    enrolledDevice('user_2', "Someone else's phone");

    $written = app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());

    expect($written)->toBe(2);
});

it('allocates a gap-free sequence per device', function (): void {
    Queue::fake();
    fakeTransport();
    $device = enrolledDevice();

    foreach (range(1, 3) as $ignored) {
        app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());
    }

    expect(PushNotification::query()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and($device->fresh()?->last_sequence)->toBe(3);
});

it('marks a delivered notification and clears the device failure count', function (): void {
    $transport = fakeTransport();
    $device = enrolledDevice();
    $device->consecutive_failures = 3;
    $device->save();

    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());

    $notification = PushNotification::query()->firstOrFail();

    expect($transport->count())->toBe(1)
        ->and($notification->status)->toBe(NotificationStatus::Delivered)
        ->and($notification->delivered_at)->not->toBeNull()
        ->and($device->fresh()?->consecutive_failures)->toBe(0);
});

it('retires the device on a permanent transport failure instead of retrying', function (): void {
    fakeTransport()->willReturn(PushResult::permanentFailure('UNREGISTERED', 404));
    $device = enrolledDevice();

    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());

    $notification = PushNotification::query()->firstOrFail();
    $fresh = $device->fresh();

    expect($notification->status)->toBe(NotificationStatus::Exhausted)
        ->and($notification->attempt)->toBe(1)
        ->and($fresh?->status)->toBe(DeviceStatus::Retired)
        // A retired device must not keep a live push capability in the database.
        ->and($fresh?->token_encrypted)->toBeNull();
});

it('backs off exponentially on a transient failure', function (): void {
    fakeTransport()->willReturn(PushResult::transientFailure('UNAVAILABLE', 503));
    enrolledDevice();

    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());

    $notification = PushNotification::query()->firstOrFail();

    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->attempt)->toBe(1)
        ->and($notification->next_retry_at)->not->toBeNull();
});

it('treats a throwing transport as transient so a broken transport never breaks the caller', function (): void {
    fakeTransport()->willThrow();
    enrolledDevice();

    $written = app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());

    expect($written)->toBe(1)
        ->and(PushNotification::query()->firstOrFail()->status)->toBe(NotificationStatus::Failed);
});

it('settles as Skipped without touching device health when no transport is configured', function (): void {
    // The default NullPushTransport. This must NOT look like a dead token, or the
    // first push on an unconfigured install would retire the whole estate.
    $device = enrolledDevice();

    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());

    $fresh = $device->fresh();

    expect(PushNotification::query()->firstOrFail()->status)->toBe(NotificationStatus::Skipped)
        ->and($fresh?->status)->toBe(DeviceStatus::Active)
        ->and($fresh?->consecutive_failures)->toBe(0);
});

it('expires an overdue approval instead of delivering it late', function (): void {
    $transport = fakeTransport();
    enrolledDevice();

    Queue::fake();
    app(PushDispatcher::class)->dispatch(
        'user_1',
        NotificationKind::Approval,
        samplePayload(),
        Carbon::now()->addSeconds(30),
    );

    $notification = PushNotification::query()->firstOrFail();

    Carbon::setTestNow(Carbon::now()->addMinutes(2));

    app(PushDispatcher::class)->deliver($notification->id);

    // A prompt landing after the CIBA window is worse than none — the user would tap
    // Approve and get an error.
    expect($notification->fresh()?->status)->toBe(NotificationStatus::Expired)
        ->and($transport->count())->toBe(0);

    Carbon::setTestNow();
});

it('settles rather than returns when the device disappeared under the job', function (): void {
    Queue::fake();
    fakeTransport();
    $device = enrolledDevice();

    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());
    $notification = PushNotification::query()->firstOrFail();

    $device->delete();

    app(PushDispatcher::class)->deliver($notification->id);

    // Returning would leave it Pending forever: unsendable, unprunable, and re-selected
    // by the stranded rescue on every sweep from then on.
    expect($notification->fresh()?->status)->toBe(NotificationStatus::Exhausted);
});

it('is idempotent so a duplicate job never re-sends', function (): void {
    $transport = fakeTransport();
    enrolledDevice();

    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());
    $notification = PushNotification::query()->firstOrFail();

    app(PushDispatcher::class)->deliver($notification->id);
    app(PushDispatcher::class)->deliver($notification->id);

    expect($transport->count())->toBe(1);
});

it('parks a notification without charging an attempt while the breaker is open', function (): void {
    fakeTransport()->willReturn(PushResult::transientFailure('UNAVAILABLE', 503));
    $device = enrolledDevice();

    // Trip the breaker: the default threshold is 5 consecutive failures.
    $device->consecutive_failures = 5;
    $device->circuit_opened_at = Carbon::now();
    $device->save();

    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());

    $notification = PushNotification::query()->firstOrFail();

    // The trip is the device's fault, not this notification's. Charging it would burn
    // a dead handset's whole backlog through every attempt inside one cooldown.
    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->attempt)->toBe(0)
        ->and($notification->next_retry_at)->not->toBeNull();
});

it('rescues a stranded notification whose job was lost', function (): void {
    Queue::fake();
    fakeTransport();
    enrolledDevice();

    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());

    // Nothing ran it, and it is now older than the stranded window.
    Carbon::setTestNow(Carbon::now()->addSeconds(1000));

    expect(app(PushDispatcher::class)->retryPending(50))->toBe(1);

    Carbon::setTestNow();
});

it('terminalises orphans so they cannot starve the sweep', function (): void {
    Queue::fake();
    fakeTransport();
    $device = enrolledDevice();

    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());
    $device->delete();

    // Orphans sort to the head of the created_at ordering, so left alone they would
    // consume every slot of the limit before any live work was reached.
    expect(app(PushDispatcher::class)->retryPending(50))->toBe(0)
        ->and(PushNotification::query()->firstOrFail()->status)->toBe(NotificationStatus::Exhausted);
});

it('dead-letters once attempts are exhausted', function (): void {
    fakeTransport()->willReturn(PushResult::transientFailure('UNAVAILABLE', 503));
    config()->set('id-devices.max_attempts', 2);
    enrolledDevice();

    app(PushDispatcher::class)->dispatch('user_1', NotificationKind::Approval, samplePayload());
    $notification = PushNotification::query()->firstOrFail();

    expect($notification->status)->toBe(NotificationStatus::Failed);

    app(PushDispatcher::class)->deliver($notification->id);

    expect($notification->fresh()?->status)->toBe(NotificationStatus::Exhausted);
});
