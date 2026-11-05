<?php

declare(strict_types=1);

use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\AuthenticatorProvisioner;
use Cbox\Id\Devices\Support\EnrolmentToken;
use Cbox\Id\Devices\Support\RevokeAuthenticatorDevice;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Exceptions\InvalidGrant;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['id-devices.enabled' => true]);
    $this->client = app(AuthenticatorProvisioner::class)->ensure('localhost');
    $this->subject = app(Subjects::class)->create('phone@example.test', 'Phone owner', 'supersecret123')->id;
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);
    $this->pem = $pem;
    $details = openssl_pkey_get_details($key);
    $this->jwk = ['e' => JWT::urlsafeB64Encode($details['rsa']['e']), 'kty' => 'RSA', 'n' => JWT::urlsafeB64Encode($details['rsa']['n'])];
    $this->jkt = JWT::urlsafeB64Encode(hash('sha256', json_encode($this->jwk, JSON_UNESCAPED_SLASHES), true));
    $this->app->instance(TokenIntrospector::class, new class($this->subject, $this->client->client_id, $this->jkt) implements TokenIntrospector
    {
        public function __construct(private string $subject, private string $client, private string $jkt) {}

        public function introspect(string $token): Introspection
        {
            return Introspection::active($this->subject, $token === 'other-client' ? 'other' : $this->client,
                ['devices.manage', 'approvals.read', 'approvals.write'],
                $token === 'bearer' ? [] : ['cnf' => ['jkt' => $this->jkt]]);
        }

        public function revoke(string $jti): void {}
    });
});

function phoneProof(string $method, string $path, string $token = 'phone-token'): array
{
    $proof = JWT::encode([
        'jti' => (string) Str::uuid(), 'iat' => time(), 'htm' => $method,
        'htu' => 'http://localhost'.$path,
        'ath' => JWT::urlsafeB64Encode(hash('sha256', $token, true)),
    ], test()->pem, 'RS256', null, ['typ' => 'dpop+jwt', 'jwk' => test()->jwk]);

    return ['Authorization' => 'DPoP '.$token, 'DPoP' => $proof];
}

function registerPhone(): string
{
    return test()->postJson('/api/v1/devices', [
        'install_id' => (string) Str::ulid(), 'platform' => 'ios', 'name' => 'My iPhone',
        'enrolment_token' => app(EnrolmentToken::class)->mint(test()->subject),
    ], phoneProof('POST', '/api/v1/devices'))->assertCreated()->json('data.id');
}

it('requires the dedicated client and DPoP even during enrolment', function (): void {
    $this->getJson('/api/v1/authenticator/me', ['Authorization' => 'Bearer bearer'])->assertForbidden();
    $this->getJson('/api/v1/authenticator/me', ['Authorization' => 'DPoP phone-token'])->assertUnauthorized();
    $this->getJson('/api/v1/authenticator/me', phoneProof('GET', '/api/v1/authenticator/me', 'other-client'))->assertForbidden();
    $this->getJson('/api/v1/approvals', phoneProof('GET', '/api/v1/approvals'))->assertForbidden();
});

it('enrols without push and returns verified identity', function (): void {
    $this->getJson('/api/v1/authenticator/me', phoneProof('GET', '/api/v1/authenticator/me'))
        ->assertOk()->assertJsonPath('data.subject', $this->subject)->assertJsonPath('data.label', 'phone@example.test');
    $id = registerPhone();
    expect(Device::query()->findOrFail($id)->token_encrypted)->toBeNull();
    $this->getJson('/api/v1/approvals', phoneProof('GET', '/api/v1/approvals'))->assertOk();
});

it('revokes the phone immediately and cannot resurrect it with its old key', function (): void {
    $id = registerPhone();
    $refresh = app(RefreshTokens::class)->issue($this->client, $this->subject, null, ['devices.manage'], dpopJkt: $this->jkt);
    $device = Device::query()->findOrFail($id);
    app(RevokeAuthenticatorDevice::class)->revoke($device);
    expect($device->fresh()->status)->toBe(DeviceStatus::Revoked);
    $this->getJson('/api/v1/approvals', phoneProof('GET', '/api/v1/approvals'))->assertForbidden()->assertJsonPath('error', 'device_revoked');
    $this->postJson('/api/v1/devices', [
        'install_id' => (string) Str::ulid(), 'platform' => 'ios', 'name' => 'Resurrected',
        'enrolment_token' => app(EnrolmentToken::class)->mint($this->subject),
    ], phoneProof('POST', '/api/v1/devices'))->assertForbidden();
    expect(fn () => app(RefreshTokens::class)->rotate($this->client->client_id, $refresh, $this->jkt))
        ->toThrow(InvalidGrant::class);
});

it('keeps polling available when the push transport retires a token', function (): void {
    $id = registerPhone();
    Device::query()->whereKey($id)->update(['status' => DeviceStatus::Retired]);
    $this->getJson('/api/v1/approvals', phoneProof('GET', '/api/v1/approvals'))->assertOk();
});

it('refuses the API when the feature is disabled', function (): void {
    config(['id-devices.enabled' => false]);
    $this->getJson('/api/v1/authenticator/me', phoneProof('GET', '/api/v1/authenticator/me'))->assertNotFound();
});

it('enforces device revocation for a genuinely issued DPoP access token', function (): void {
    $this->app->forgetInstance(TokenIntrospector::class);
    $token = app(TokenIssuer::class)->issueForUser(
        $this->client, $this->subject, null, ['devices.manage', 'approvals.read'], dpopJkt: $this->jkt,
    )->token;
    $id = $this->postJson('/api/v1/devices', [
        'install_id' => (string) Str::ulid(), 'platform' => 'ios', 'name' => 'Real token phone',
        'enrolment_token' => app(EnrolmentToken::class)->mint($this->subject),
    ], phoneProof('POST', '/api/v1/devices', $token))->assertCreated()->json('data.id');
    $this->getJson('/api/v1/approvals', phoneProof('GET', '/api/v1/approvals', $token))->assertOk();
    app(RevokeAuthenticatorDevice::class)->revoke(Device::query()->findOrFail($id));
    $this->getJson('/api/v1/approvals', phoneProof('GET', '/api/v1/approvals', $token))
        ->assertForbidden()->assertJsonPath('error', 'device_revoked');
});

it('publishes a privacy page without requiring sign-in', function (): void {
    $this->get('/authenticator/privacy')->assertOk()->assertSee('Keychain');
});
