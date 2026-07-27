<?php

declare(strict_types=1);

use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Transports\FcmPushTransport;
use Cbox\Id\Devices\ValueObjects\PushMessage;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;

/**
 * The error mapping is the consequential part of this class: it decides whether a
 * handset is retired outright or merely retried. Getting it wrong in either direction
 * is bad — retire too eagerly and a user silently stops receiving approval prompts;
 * retry a dead token and every push burns twelve attempts over eleven hours.
 */
function fcmTransport(Http $http): FcmPushTransport
{
    return new FcmPushTransport(
        $http,
        Cache::store('array'),
        credentialsPath: writeServiceAccount(),
        projectId: 'test-project',
    );
}

function writeServiceAccount(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    $path = tempnam(sys_get_temp_dir(), 'sa').'.json';
    file_put_contents($path, json_encode([
        'client_email' => 'push@test-project.iam.gserviceaccount.com',
        'private_key' => $pem,
    ], JSON_THROW_ON_ERROR));

    return $path;
}

function fcmMessage(): PushMessage
{
    return new PushMessage(
        token: 'device-token',
        platform: DevicePlatform::Ios,
        kind: NotificationKind::Approval,
        payload: new PushPayload('Approval request', 'Open Cbox ID.', ['url' => 'cboxauth://approvals'], 'req-1'),
    );
}

function fcmHttp(array $sendResponse, int $sendStatus): Http
{
    $http = new Http;
    $http->fake([
        'oauth2.googleapis.com/*' => $http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => $http::response($sendResponse, $sendStatus),
    ]);

    return $http;
}

it('reports a successful send as delivered', function (): void {
    $result = fcmTransport(fcmHttp(['name' => 'projects/test/messages/1'], 200))->send(fcmMessage());

    expect($result->delivered)->toBeTrue()
        ->and($result->configured)->toBeTrue();
});

it('treats UNREGISTERED as permanent so the device is retired at once', function (): void {
    $result = fcmTransport(fcmHttp([
        'error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]],
    ], 404))->send(fcmMessage());

    expect($result->delivered)->toBeFalse()
        ->and($result->permanent)->toBeTrue()
        ->and($result->error)->toBe('UNREGISTERED');
});

it('does NOT retire the device on a project-level 404', function (): void {
    // A wrong ID_DEVICES_FCM_PROJECT_ID returns 404 with no errorCode detail. Treating
    // that as token death would wipe the sealed token of every device in the estate on
    // its first push, and recovery would need every user to re-open the app.
    $result = fcmTransport(fcmHttp([
        'error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.'],
    ], 404))->send(fcmMessage());

    expect($result->delivered)->toBeFalse()
        ->and($result->permanent)->toBeFalse();
});

it('does NOT retire the device on a malformed message', function (): void {
    // INVALID_ARGUMENT is FCM rejecting OUR payload, not the handset's token.
    $result = fcmTransport(fcmHttp([
        'error' => ['status' => 'INVALID_ARGUMENT', 'details' => [['errorCode' => 'INVALID_ARGUMENT']]],
    ], 400))->send(fcmMessage());

    expect($result->permanent)->toBeFalse();
});

it('treats SENDER_ID_MISMATCH as permanent', function (): void {
    // The token belongs to a different Firebase sender, so it is dead to us.
    $result = fcmTransport(fcmHttp([
        'error' => ['status' => 'PERMISSION_DENIED', 'details' => [['errorCode' => 'SENDER_ID_MISMATCH']]],
    ], 403))->send(fcmMessage());

    expect($result->permanent)->toBeTrue();
});

it('treats a quota error as transient so the token survives', function (): void {
    $result = fcmTransport(fcmHttp([
        'error' => ['status' => 'RESOURCE_EXHAUSTED', 'details' => [['errorCode' => 'QUOTA_EXCEEDED']]],
    ], 429))->send(fcmMessage());

    expect($result->delivered)->toBeFalse()
        ->and($result->permanent)->toBeFalse()
        ->and($result->code)->toBe(429);
});

it('treats a backend outage as transient', function (): void {
    $result = fcmTransport(fcmHttp(['error' => ['status' => 'UNAVAILABLE']], 503))->send(fcmMessage());

    expect($result->permanent)->toBeFalse()
        ->and($result->code)->toBe(503);
});

it('treats a credentials failure as transient rather than blaming the handset', function (): void {
    $http = new Http;
    $http->fake(['oauth2.googleapis.com/*' => $http::response(['error' => 'invalid_grant'], 400)]);

    $result = fcmTransport($http)->send(fcmMessage());

    // Our configuration is broken, not the user's phone. Retiring the device here would
    // silently unenroll the whole estate on a bad key rotation.
    expect($result->permanent)->toBeFalse()
        ->and($result->error)->toContain('FCM auth failed');
});

it('sends an apns envelope for iOS with a collapse id', function (): void {
    $http = fcmHttp(['name' => 'ok'], 200);

    fcmTransport($http)->send(fcmMessage());

    $http->assertSent(function ($request): bool {
        if (! str_contains($request->url(), 'fcm.googleapis.com')) {
            return true;
        }

        $message = $request->data()['message'] ?? [];

        return ($message['apns']['headers']['apns-collapse-id'] ?? null) === 'req-1'
            && ($message['apns']['headers']['apns-priority'] ?? null) === '10'
            // The lock screen must stay vague; specifics are fetched over TLS later.
            && ($message['notification']['title'] ?? null) === 'Approval request';
    });
});
