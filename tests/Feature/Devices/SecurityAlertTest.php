<?php

declare(strict_types=1);

use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Testing\FakePushTransport;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Kernel\Events\Contracts\EventBus;
use Cbox\Id\Kernel\Events\ValueObjects\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function alertDevice(string $subjectId = 'user_1'): Device
{
    config()->set('id-devices.enabled', true);

    $device = new Device;
    $device->fill([
        'subject_id' => $subjectId,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Android,
        'name' => 'Alert Pixel',
        'status' => DeviceStatus::Active,
    ]);
    $device->save();
    $device->token_encrypted = app(SecretBox::class)->seal('fcm-token', $device->secretContext());
    $device->save();

    return $device;
}

/** Emit to the outbox, then drain it the way the scheduled relay does. */
function relay(DomainEvent $event): void
{
    $bus = app(EventBus::class);
    $bus->emit($event);
    $bus->flushPending();
}

it('alerts enrolled devices about a configured event', function (): void {
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    alertDevice();

    relay(new DomainEvent('user.login', ['user_id' => 'user_1']));

    expect($transport->count())->toBe(1)
        ->and($transport->latest()?->kind)->toBe(NotificationKind::SecurityAlert)
        ->and($transport->latest()?->payload->body)->toBe('Your account was signed in to.');
});

it('ignores an event type the operator has not opted into', function (): void {
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    alertDevice();
    config()->set('id-devices.alerts', ['user.session_revoked']);

    relay(new DomainEvent('user.login', ['user_id' => 'user_1']));

    expect($transport->count())->toBe(0);
});

it('sends nothing while the module is disabled', function (): void {
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    alertDevice();
    config()->set('id-devices.enabled', false);

    relay(new DomainEvent('user.login', ['user_id' => 'user_1']));

    expect($transport->count())->toBe(0);
});

it('keeps the alert off the lock screen in specifics', function (): void {
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    alertDevice();

    relay(new DomainEvent('user.login', ['user_id' => 'user_1']));

    // Where from, and from what address, is account-sensitive. It is read in the app.
    expect($transport->latest()?->payload->title)->toBe('Cbox ID');
});

it('does not fail the relay when the push path is broken', function (): void {
    app()->instance(PushTransport::class, (new FakePushTransport)->willThrow());

    alertDevice();

    // A listener throw makes the relay retry the whole event, re-running every other
    // listener with it. An alert is not worth that.
    relay(new DomainEvent('user.login', ['user_id' => 'user_1']));
})->throwsNoExceptions();
