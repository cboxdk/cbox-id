<?php

declare(strict_types=1);

use Cbox\Id\Devices\Contracts\PushTransport;
use Cbox\Id\Devices\Decorators\PushNotifyingBackchannelAuthentication;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\PushNotification;
use Cbox\Id\Devices\Testing\FakePushTransport;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A subject with one enrolled handset, plus an agent client to raise CIBA requests.
 *
 * @return array{0: string, 1: Client}
 */
function cibaSubjectWithDevice(): array
{
    config()->set('id-devices.enabled', true);

    $subject = app(Subjects::class)->create('ciba@acme.test', 'CIBA User', 'supersecret123');

    $device = new Device;
    $device->fill([
        'subject_id' => $subject->id,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => 'CIBA iPhone',
        'status' => DeviceStatus::Active,
    ]);
    $device->save();
    $device->token_encrypted = app(SecretBox::class)->seal('fcm-token', $device->secretContext());
    $device->save();

    $client = app(ClientRegistry::class)->register(
        new NewClient('Agent', ClientType::Confidential, scopes: ['openid'])
    )->client;

    return [$subject->id, $client];
}

it('decorates the CIBA contract so the prompt is pushed, not relayed', function (): void {
    // The whole design rests on this: the domain event goes to an outbox that is
    // relayed once a minute, against a 300-second CIBA TTL.
    expect(app(BackchannelAuthentication::class))
        ->toBeInstanceOf(PushNotifyingBackchannelAuthentication::class);
});

it('pushes an approval prompt synchronously when a CIBA request is raised', function (): void {
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    [$subjectId, $client] = cibaSubjectWithDevice();

    app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId, 'Transfer DKK 4,200');

    // Sent during the request itself — no relay tick, no scheduler.
    expect($transport->count())->toBe(1);

    $message = $transport->latest();

    expect($message?->kind)->toBe(NotificationKind::Approval)
        ->and($message?->token)->toBe('fcm-token');
});

it('keeps the binding message off the lock screen', function (): void {
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    [$subjectId, $client] = cibaSubjectWithDevice();

    app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId, 'Transfer DKK 4,200 to ACME ApS');

    $payload = $transport->latest()?->payload;

    // The binding message is the transaction description — the one detail CIBA exists
    // to protect, and the one an attacker holding the handset would most like to read.
    // It must be fetched over TLS after the app opens, never pushed.
    expect($payload?->title)->toBe('Approval request')
        ->and($payload?->body)->not->toContain('4,200')
        ->and(implode(' ', $payload?->data ?? []))->not->toContain('4,200');
});

it('carries a deep link to the specific approval', function (): void {
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    [$subjectId, $client] = cibaSubjectWithDevice();

    app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId);

    $requestId = PushNotification::query()->firstOrFail()->payload->data['request_id'] ?? null;

    expect($requestId)->not->toBeNull()
        ->and($transport->latest()?->payload->data['url'] ?? '')->toBe('cboxauth://approvals/'.$requestId);
});

it('omits the request id when the operator has turned it off', function (): void {
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    [$subjectId, $client] = cibaSubjectWithDevice();
    config()->set('id-devices.include_request_id_in_push', false);

    app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId);

    $data = $transport->latest()?->payload->data ?? [];

    expect($data)->not->toHaveKey('request_id')
        ->and($data['url'] ?? '')->toBe('cboxauth://approvals');
});

it('sets the push deadline from the CIBA expiry', function (): void {
    app()->instance(PushTransport::class, new FakePushTransport);

    [$subjectId, $client] = cibaSubjectWithDevice();

    $result = app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId);

    $notification = PushNotification::query()->firstOrFail();

    expect($notification->expires_at)->not->toBeNull()
        ->and($notification->expires_at?->diffInSeconds(now()->addSeconds($result->expiresIn), true))
        ->toBeLessThan(5);
});

it('refuses to push for a client outside the allowlist but still raises the request', function (): void {
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    [$subjectId, $client] = cibaSubjectWithDevice();
    config()->set('id-devices.ciba.client_allowlist', ['cid_someone_else']);

    $result = app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId);

    // Push fatigue attacks the human-in-the-loop guarantee CIBA exists for, so this is
    // a hard refusal. The request itself still stands — the client may poll, and the
    // console can still approve.
    expect($transport->count())->toBe(0)
        ->and($result->requestId)->not->toBeEmpty();
});

it('does not push at all while the module is disabled', function (): void {
    $transport = new FakePushTransport;
    app()->instance(PushTransport::class, $transport);

    [$subjectId, $client] = cibaSubjectWithDevice();
    config()->set('id-devices.enabled', false);

    app(BackchannelAuthentication::class)->request($client, ['openid'], $subjectId);

    expect($transport->count())->toBe(0)
        ->and(PushNotification::query()->count())->toBe(0);
});
