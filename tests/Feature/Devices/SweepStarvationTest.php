<?php

declare(strict_types=1);

use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Enums\NotificationStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Devices\Testing\FakePushTransport;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A notification parked by an open circuit breaker is NOT charged an attempt — the trip
 * is the device's fault, not the notification's. That is correct, but it means such a
 * row can never reach max_attempts on its own, so something else has to bound it.
 * Otherwise one permanently soft-failing handset accumulates a backlog that, being the
 * oldest rows in the table and the sweep being ordered oldest-first, occupies every slot
 * of retry_limit forever and starves every other tenant.
 */
function starvingDevice(string $subjectId): Device
{
    $device = new Device;
    $device->fill([
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => 'Starving iPhone',
        'status' => DeviceStatus::Active,
    ]);
    $device->save();
    $device->token_encrypted = app(SecretBox::class)->seal('fcm-token', $device->secretContext());
    $device->save();

    return $device;
}

it('gives security alerts a deadline so they cannot pile up unbounded', function (): void {
    Queue::fake();
    app()->instance(PushTransport::class, new FakePushTransport);

    starvingDevice('user_1');

    app(PushDispatcher::class)->dispatch(
        'user_1',
        NotificationKind::SecurityAlert,
        new PushPayload('Cbox ID', 'Signed in.'),
        Carbon::now()->addSeconds(86400),
    );

    expect(PushNotification::query()->firstOrFail()->expires_at)->not->toBeNull();
});

it('settles expired rows in bulk rather than spending a queue job on each', function (): void {
    Queue::fake();
    app()->instance(PushTransport::class, new FakePushTransport);

    starvingDevice('user_1');

    foreach (range(1, 5) as $ignored) {
        app(PushDispatcher::class)->dispatch(
            'user_1',
            NotificationKind::Approval,
            new PushPayload('Approval request', 'Open Cbox ID.'),
            Carbon::now()->addSeconds(300),
        );
    }

    Queue::fake();

    // Past both the CIBA deadline and the stranded window. Every one of these is
    // undeliverable by construction — the stranded window (900s) is longer than the CIBA
    // TTL (300s), so a rescued approval is always already expired.
    Carbon::setTestNow(Carbon::now()->addSeconds(1000));

    expect(app(PushDispatcher::class)->retryPending(50))->toBe(0)
        ->and(PushNotification::query()->where('status', NotificationStatus::Expired)->count())->toBe(5);

    Queue::assertNothingPushed();

    Carbon::setTestNow();
});

it('does not let one dead handset crowd live work out of the sweep', function (): void {
    Queue::fake();
    app()->instance(PushTransport::class, new FakePushTransport);

    starvingDevice('user_dead');
    starvingDevice('user_live');

    // The dead handset's backlog is the OLDEST in the table, so it sorts to the head.
    foreach (range(1, 60) as $ignored) {
        app(PushDispatcher::class)->dispatch(
            'user_dead',
            NotificationKind::SecurityAlert,
            new PushPayload('Cbox ID', 'Signed in.'),
            Carbon::now()->addSeconds(86400),
        );
    }

    Carbon::setTestNow(Carbon::now()->addSeconds(90000));

    // A live approval arrives now, well after the backlog.
    app(PushDispatcher::class)->dispatch(
        'user_live',
        NotificationKind::Approval,
        new PushPayload('Approval request', 'Open Cbox ID.'),
        Carbon::now()->addSeconds(300),
    );

    Queue::fake();

    // The backlog is all past its deadline, so it is settled set-based before selection
    // and the live approval is reached despite a retry_limit far below the backlog size.
    $queued = app(PushDispatcher::class)->retryPending(10);

    expect(PushNotification::query()->where('status', NotificationStatus::Expired)->count())->toBe(60)
        ->and($queued)->toBe(0);

    Carbon::setTestNow();
});
