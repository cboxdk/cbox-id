<?php

declare(strict_types=1);

use App\Platform\EnvironmentSudo;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Support\AuthenticatorProvisioner;
use Cbox\Id\Devices\Support\DeviceApprovalEvidence;
use Cbox\Id\Devices\Support\EnrolmentToken;
use Cbox\Id\Devices\Support\RevokeAuthenticatorDevice;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Cbox\Id\Platform\PlatformRoot;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withCredentials();
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

function mobileProof(string $method, string $path, string $token = 'phone-token'): array
{
    $proof = JWT::encode([
        'jti' => (string) Str::uuid(), 'iat' => time(), 'htm' => $method,
        'htu' => 'http://localhost'.$path,
        'ath' => JWT::urlsafeB64Encode(hash('sha256', $token, true)),
    ], test()->pem, 'RS256', null, ['typ' => 'dpop+jwt', 'jwk' => test()->jwk]);

    return ['Authorization' => 'DPoP '.$token, 'DPoP' => $proof];
}

function registerMobilePhone(): string
{
    return test()->postJson('/api/v1/devices', [
        'install_id' => (string) Str::ulid(), 'platform' => 'ios', 'name' => 'My iPhone',
        'enrolment_token' => app(EnrolmentToken::class)->mint(test()->subject),
    ], mobileProof('POST', '/api/v1/devices'))->assertCreated()->json('data.id');
}

function startMobileLogin(): string
{
    request()->setLaravelSession(app('session.store'));
    app(PlatformAuth::class)->holdForMfa(request(), test()->subject);
    test()->withCookie(config('session.cookie'), session()->getId());
    test()->postJson('/mfa/mobile', ['operation' => 'start'])->assertOk()->assertJsonPath('status', 'pending');

    return session('cbox.browser_approval.login.id');
}

function approveMobile(string $id): void
{
    $path = '/api/v1/approvals/'.$id.'/approve';
    test()->postJson($path, [], mobileProof('POST', $path))->assertOk();
}

it('completes password login with the existing signed phone approval and preserves MFA', function (): void {
    registerMobilePhone();
    $this->post('/login', ['email' => 'phone@example.test', 'password' => 'supersecret123'])->assertRedirect(route('mfa'));
    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->postJson('/mfa/mobile', ['operation' => 'start'])->assertOk();
    $id = session('cbox.browser_approval.login.id');
    $this->getJson('/api/v1/approvals', mobileProof('GET', '/api/v1/approvals'))->assertJsonPath('data.0.id', $id);
    approveMobile($id);
    expect(app(DeviceApprovalEvidence::class)->valid($id, $this->subject))->toBeTrue();
    $this->postJson('/mfa/mobile', ['operation' => 'complete'])->assertOk()->assertJsonPath('status', 'confirmed');
    $session = app(SessionManager::class)->active(session(PlatformAuth::SESSION_KEY));
    expect($session->amr)->toContain('pwd', 'device', 'mfa');
    $this->postJson('/mfa/mobile', ['operation' => 'complete'])->assertStatus(409);
});

it('does not accept a browser approval as a second factor', function (): void {
    registerMobilePhone();
    $id = startMobileLogin();
    app(BackchannelAuthentication::class)->approve($id, $this->subject);
    $this->postJson('/mfa/mobile', ['operation' => 'complete'])->assertStatus(409);
    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('refuses pending denied expired and cancelled approvals', function (string $state): void {
    registerMobilePhone();
    $id = startMobileLogin();
    if ($state === 'denied') {
        $path = '/api/v1/approvals/'.$id.'/deny';
        $this->postJson($path, [], mobileProof('POST', $path))->assertOk();
    } elseif ($state === 'expired') {
        approveMobile($id);
        $this->travel(301)->seconds();
    } elseif ($state === 'cancelled') {
        approveMobile($id);
        $this->postJson('/mfa/mobile', ['operation' => 'cancel'])->assertOk();
    }
    $this->postJson('/mfa/mobile', ['operation' => 'complete'])->assertStatus(409);
    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
})->with(['pending', 'denied', 'expired', 'cancelled']);

it('invalidates approval when the phone is revoked before completion', function (): void {
    $device = registerMobilePhone();
    $id = startMobileLogin();
    approveMobile($id);
    app(RevokeAuthenticatorDevice::class)->revoke(Device::query()->findOrFail($device));
    $this->postJson('/mfa/mobile', ['operation' => 'complete'])->assertStatus(409);
});

it('cannot replay approval into another login even for the same subject', function (): void {
    registerMobilePhone();
    $id = startMobileLogin();
    approveMobile($id);
    request()->setLaravelSession(app('session.store'));
    app(PlatformAuth::class)->holdForMfa(request(), $this->subject);
    session()->save();
    $this->postJson('/mfa/mobile', ['operation' => 'complete'])->assertStatus(409);
});

it('cannot borrow another subjects approval', function (): void {
    registerMobilePhone();
    $id = startMobileLogin();
    approveMobile($id);
    $other = app(Subjects::class)->create('different@example.test', 'Other', 'supersecret123');
    session(['cbox.mfa_pending' => $other->id]);
    session()->save();
    $this->postJson('/mfa/mobile', ['operation' => 'complete'])->assertStatus(409);
});

it('does not send on a GET and reuses a pending request without sending again', function (): void {
    registerMobilePhone();
    request()->setLaravelSession(app('session.store'));
    app(PlatformAuth::class)->holdForMfa(request(), $this->subject);
    $this->getJson('/mfa/mobile')->assertOk()->assertJsonPath('available', true)->assertJsonPath('status', 'idle');
    expect(BackchannelAuthRequest::query()->count())->toBe(0);
    $id = startMobileLogin();
    $this->postJson('/mfa/mobile', ['operation' => 'start'])->assertOk();
    expect(session('cbox.browser_approval.login.id'))->toBe($id)
        ->and(BackchannelAuthRequest::query()->count())->toBe(1);
});

it('bounds repeated notifications across browser sessions', function (): void {
    registerMobilePhone();
    for ($i = 0; $i < 3; $i++) {
        startMobileLogin();
        $this->postJson('/mfa/mobile', ['operation' => 'cancel'])->assertOk();
    }
    $this->postJson('/mfa/mobile', ['operation' => 'start'])->assertStatus(429);
});

it('confirms console step up using the same device approval without changing login', function (): void {
    registerMobilePhone();
    request()->setLaravelSession(app('session.store'));
    app(PlatformAuth::class)->establish(request(), $this->subject, ['pwd', 'otp']);
    $this->withCookie(config('session.cookie'), session()->getId());
    $before = session(PlatformAuth::SESSION_KEY);
    $this->postJson('/sudo/mobile', ['operation' => 'start'])->assertOk();
    approveMobile(session('cbox.browser_approval.organization.id'));
    $this->postJson('/sudo/mobile', ['operation' => 'complete'])->assertOk();
    expect(app(Sudo::class)->confirmed())->toBeTrue()
        ->and(app(EnvironmentSudo::class)->confirmed())->toBeFalse()
        ->and(session(PlatformAuth::SESSION_KEY))->toBe($before);
});

it('binds completion to the original browser session', function (): void {
    registerMobilePhone();
    $id = startMobileLogin();
    approveMobile($id);
    session()->migrate(true);
    session()->save();
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->postJson('/mfa/mobile', ['operation' => 'complete'])->assertStatus(409);
});

it('requires a pending primary factor and hides the option when disabled', function (): void {
    registerMobilePhone();
    $this->postJson('/mfa/mobile', ['operation' => 'start'])->assertStatus(409);
    startMobileLogin();
    config(['id-devices.enabled' => false]);
    $this->getJson('/mfa/mobile')->assertOk()->assertJsonPath('available', false);
    $this->postJson('/mfa/mobile', ['operation' => 'complete'])->assertStatus(409);
});

it('keeps phone approvals inside their issuing environment', function (): void {
    registerMobilePhone();
    $id = startMobileLogin();
    approveMobile($id);
    $valid = app(EnvironmentContext::class)->runAs(
        GenericEnvironment::of('another-environment'),
        fn () => app(DeviceApprovalEvidence::class)->valid($id, $this->subject),
    );
    expect($valid)->toBeFalse();
});

it('confirms tenant admin in the root realm and never opens the organization sudo window', function (): void {
    $setup = crudSetup();
    $subject = $setup['subjectId'];
    app(PlatformRoot::class)->run(function () use ($subject): void {
        Device::query()->create(['subject_id' => $subject, 'install_id' => (string) Str::ulid(),
            'platform' => 'ios', 'name' => 'Admin phone', 'status' => 'active', 'dpop_jkt' => $this->jkt]);
    });
    $this->withCookie(config('session.cookie'), session()->getId());
    $this->postJson(envConsole('/sudo/mobile'), ['operation' => 'start'])->assertOk()->assertJsonPath('available', true);
    $id = session('cbox.browser_approval.environment.id');
    app(PlatformRoot::class)->run(function () use ($id, $subject): void {
        app(BackchannelAuthentication::class)->approve($id, $subject);
        app(DeviceApprovalEvidence::class)->record($id,
            Introspection::active($subject, $this->client->client_id, ['approvals.write'], ['cnf' => ['jkt' => $this->jkt]]));
    });
    $this->postJson(envConsole('/sudo/mobile'), ['operation' => 'complete'])->assertOk();
    expect(app(EnvironmentSudo::class)->confirmed())->toBeTrue()
        ->and(app(Sudo::class)->confirmed())->toBeFalse();
});

it('rejects a device key if another registration of the same key was revoked', function (): void {
    registerMobilePhone();
    $id = startMobileLogin();
    approveMobile($id);
    Device::query()->create(['subject_id' => $this->subject, 'install_id' => (string) Str::ulid(),
        'platform' => 'ios', 'name' => 'Old installation', 'status' => 'revoked', 'dpop_jkt' => $this->jkt]);
    $this->postJson('/mfa/mobile', ['operation' => 'complete'])->assertStatus(409);
});
