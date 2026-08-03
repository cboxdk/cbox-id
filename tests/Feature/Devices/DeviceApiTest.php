<?php

declare(strict_types=1);

use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\EnrolmentToken;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * A fake introspector, matching the house pattern in VaultApiTest: the API's auth path
 * runs for real without minting signed JWTs.
 */
beforeEach(function (): void {
    $this->app->instance(TokenIntrospector::class, new class implements TokenIntrospector
    {
        public function introspect(string $token): Introspection
        {
            return match ($token) {
                'alice-tok' => Introspection::active('user_alice', 'cid_authenticator', ['devices.manage', 'approvals.read', 'approvals.write'], []),
                'bob-tok' => Introspection::active('user_bob', 'cid_authenticator', ['devices.manage'], []),
                'noscope-tok' => Introspection::active('user_alice', 'cid_authenticator', [], []),
                'machine-tok' => Introspection::active(null, 'cid_machine', ['devices.manage'], []),
                default => Introspection::inactive(),
            };
        }

        public function revoke(string $jti): void {}
    });
});

function deviceAuth(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

/**
 * A first enrolment must present a short-lived code minted for the SAME subject the
 * access token names. Pass `$subject` when enrolling as anyone but Alice; put
 * `enrolment_token` in the overrides to exercise a bad one.
 */
function enrolPayload(array $overrides = [], string $subject = 'user_alice'): array
{
    return array_merge([
        'install_id' => (string) Str::ulid(),
        'platform' => 'ios',
        'push_token' => 'fcm-token-abc',
        'name' => 'Alice iPhone',
        'enrolment_token' => app(EnrolmentToken::class)->mint($subject),
    ], $overrides);
}

it('rejects an unauthenticated enrolment', function (): void {
    $this->postJson('/api/v1/devices', enrolPayload())
        ->assertStatus(401)
        ->assertHeader('WWW-Authenticate');
});

it('rejects a token missing the devices scope', function (): void {
    $this->postJson('/api/v1/devices', enrolPayload(), deviceAuth('noscope-tok'))
        ->assertStatus(403)
        ->assertJson(['error' => 'insufficient_scope']);
});

it('refuses a token with no subject', function (): void {
    // A client-credentials token has no user whose devices these would be.
    $this->postJson('/api/v1/devices', enrolPayload(), deviceAuth('machine-tok'))
        ->assertStatus(403);
});

it('enrols a device and never returns the push token', function (): void {
    $response = $this->postJson('/api/v1/devices', enrolPayload(), deviceAuth('alice-tok'))
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Alice iPhone')
        ->assertJsonPath('data.platform', 'ios')
        ->assertJsonPath('data.status', 'active');

    expect($response->json('data'))->not->toHaveKey('push_token');

    $device = Device::query()->firstOrFail();

    expect($device->subject_id)->toBe('user_alice')
        ->and(app(SecretBox::class)->open((string) $device->token_encrypted, $device->secretContext()))
        ->toBe('fcm-token-abc');
});

it('is idempotent on install_id so a token rotation is just another POST', function (): void {
    $installId = (string) Str::ulid();

    $this->postJson('/api/v1/devices', enrolPayload(['install_id' => $installId]), deviceAuth('alice-tok'))
        ->assertStatus(201);

    $this->postJson('/api/v1/devices', enrolPayload([
        'install_id' => $installId,
        'push_token' => 'fcm-token-rotated',
    ]), deviceAuth('alice-tok'))->assertStatus(200);

    expect(Device::query()->count())->toBe(1);

    $device = Device::query()->firstOrFail();

    expect(app(SecretBox::class)->open((string) $device->token_encrypted, $device->secretContext()))
        ->toBe('fcm-token-rotated');
});

it('revives a device that a permanent transport error had retired', function (): void {
    $installId = (string) Str::ulid();

    $this->postJson('/api/v1/devices', enrolPayload(['install_id' => $installId]), deviceAuth('alice-tok'));

    $device = Device::query()->firstOrFail();
    $device->status = DeviceStatus::Retired;
    $device->token_encrypted = null;
    $device->consecutive_failures = 9;
    $device->circuit_opened_at = now();
    $device->save();

    // A fresh token is exactly the evidence needed to believe in the handset again.
    $this->postJson('/api/v1/devices', enrolPayload(['install_id' => $installId]), deviceAuth('alice-tok'))
        ->assertStatus(200);

    $fresh = $device->fresh();

    expect($fresh?->status)->toBe(DeviceStatus::Active)
        ->and($fresh?->consecutive_failures)->toBe(0)
        ->and($fresh?->circuit_opened_at)->toBeNull();
});

it('refuses to reassign an install claimed by another user', function (): void {
    $installId = (string) Str::ulid();

    $this->postJson('/api/v1/devices', enrolPayload(['install_id' => $installId]), deviceAuth('alice-tok'))
        ->assertStatus(201);

    // Silently reassigning would let Bob capture Alice's push stream by guessing an id.
    $this->postJson('/api/v1/devices', enrolPayload(['install_id' => $installId]), deviceAuth('bob-tok'))
        ->assertStatus(409)
        ->assertJson(['error' => 'install_claimed']);

    expect(Device::query()->firstOrFail()->subject_id)->toBe('user_alice');
});

it('strips control characters from the device name', function (): void {
    $this->postJson('/api/v1/devices', enrolPayload(['name' => "Alice\u{0000}\u{200B}iPhone"]), deviceAuth('alice-tok'))
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'AliceiPhone');
});

it('lists only the caller own devices', function (): void {
    $this->postJson('/api/v1/devices', enrolPayload(['name' => 'Alice iPhone']), deviceAuth('alice-tok'));
    $this->postJson('/api/v1/devices', enrolPayload(['name' => 'Bob Pixel']), deviceAuth('bob-tok'));

    $this->getJson('/api/v1/devices', deviceAuth('alice-tok'))
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alice iPhone');
});

it('deregisters a device', function (): void {
    $this->postJson('/api/v1/devices', enrolPayload(), deviceAuth('alice-tok'));
    $id = Device::query()->firstOrFail()->id;

    $this->deleteJson("/api/v1/devices/{$id}", [], deviceAuth('alice-tok'))->assertStatus(204);

    expect(Device::query()->count())->toBe(0);
});

it('answers for another user device exactly as for a missing one', function (): void {
    $this->postJson('/api/v1/devices', enrolPayload(), deviceAuth('alice-tok'));
    $id = Device::query()->firstOrFail()->id;

    // A distinguishable response would confirm that Alice's handset exists.
    $this->deleteJson("/api/v1/devices/{$id}", [], deviceAuth('bob-tok'))
        ->assertStatus(404)
        ->assertJson(['error' => 'not_found', 'message' => 'No such device.']);

    $this->deleteJson('/api/v1/devices/01JZZZZZZZZZZZZZZZZZZZZZZZ', [], deviceAuth('bob-tok'))
        ->assertStatus(404)
        ->assertJson(['error' => 'not_found', 'message' => 'No such device.']);
});

it('validates the enrolment payload', function (): void {
    $this->postJson('/api/v1/devices', ['platform' => 'blackberry'], deviceAuth('alice-tok'))
        ->assertStatus(422)
        ->assertJson(['error' => 'validation_failed']);
});
