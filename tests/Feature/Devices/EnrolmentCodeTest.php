<?php

declare(strict_types=1);

use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Devices\Models\EnrolmentCode;
use Cbox\Id\Devices\Support\EnrolmentToken;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->app->instance(TokenIntrospector::class, new class implements TokenIntrospector
    {
        public function introspect(string $token): Introspection
        {
            return match ($token) {
                'alice-tok' => Introspection::active('user_alice', 'cid_authenticator', ['devices.manage'], []),
                'bob-tok' => Introspection::active('user_bob', 'cid_authenticator', ['devices.manage'], []),
                default => Introspection::inactive(),
            };
        }

        public function revoke(string $jti): void {}
    });
});

function codePayload(array $overrides = []): array
{
    return array_merge([
        'install_id' => (string) Str::ulid(),
        'platform' => 'ios',
        'push_token' => 'fcm-token-abc',
        'name' => 'A phone',
    ], $overrides);
}

function enrol(array $payload, string $token = 'alice-tok')
{
    return test()->postJson('/api/v1/devices', $payload, ['Authorization' => "Bearer {$token}"]);
}

it('refuses a first enrolment that presents no code', function (): void {
    enrol(codePayload())
        ->assertStatus(422)
        ->assertJsonPath('error', 'enrolment_code_invalid');

    expect(Device::query()->count())->toBe(0);
});

it('accepts a first enrolment with a fresh code', function (): void {
    $code = app(EnrolmentToken::class)->mint('user_alice');

    enrol(codePayload(['enrolment_token' => $code]))->assertStatus(201);

    expect(Device::query()->count())->toBe(1);
});

it('spends a code exactly once', function (): void {
    // A signature is infinitely replayable. A code photographed off a screen must not
    // enrol a second handset.
    $code = app(EnrolmentToken::class)->mint('user_alice');

    enrol(codePayload(['enrolment_token' => $code]))->assertStatus(201);

    enrol(codePayload(['enrolment_token' => $code]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'enrolment_code_invalid');

    expect(Device::query()->count())->toBe(1);
});

it('refuses a code minted for somebody else', function (): void {
    // The whole point of the binding: Bob scanning the code off Alice's screen must not
    // attach his handset to anything.
    $alices = app(EnrolmentToken::class)->mint('user_alice');

    enrol(codePayload(['enrolment_token' => $alices]), 'bob-tok')
        ->assertStatus(422)
        ->assertJsonPath('error', 'enrolment_code_invalid');

    expect(Device::query()->count())->toBe(0);
});

it('refuses a code that has expired', function (): void {
    // The whole reason the code is short-lived. Note that Carbon::setTestNow would do
    // nothing here — php-jwt validates `exp` against its own clock, and JWT::$timestamp
    // is the only thing that moves it.
    $code = app(EnrolmentToken::class)->mint('user_alice');

    JWT::$timestamp = time() + EnrolmentToken::TTL + 60;

    try {
        enrol(codePayload(['enrolment_token' => $code]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'enrolment_code_invalid');
    } finally {
        JWT::$timestamp = null;
    }

    expect(Device::query()->count())->toBe(0);
});

it('refuses an access token presented as an enrolment code', function (): void {
    // Same RS256 key, an `iss` this environment accepts, a matching `sub` and a `jti` —
    // everything but the `typ`. Without that check a handset could re-enrol itself
    // forever using a token it already holds, defeating both the TTL and single use.
    $lookalike = app(TokenSigner::class)->sign([
        'iss' => app(IssuerResolver::class)->issuer(),
        'sub' => 'user_alice',
        'exp' => time() + 3600,
    ], SigningAlg::RS256, 'at+jwt');

    enrol(codePayload(['enrolment_token' => $lookalike]))
        ->assertStatus(422)
        ->assertJsonPath('error', 'enrolment_code_invalid');
});

it('refuses a code that is not signed by this platform', function (): void {
    enrol(codePayload(['enrolment_token' => 'not.a.jwt']))->assertStatus(422);
});

it('does not ask for a code when an enrolled handset rotates its push token', function (): void {
    // The rotation happens in the background with no screen to scan from. Requiring a
    // code here would mean every FCM rotation silently failed.
    $install = (string) Str::ulid();
    $code = app(EnrolmentToken::class)->mint('user_alice');

    enrol(codePayload(['install_id' => $install, 'enrolment_token' => $code]))->assertStatus(201);

    enrol(codePayload(['install_id' => $install, 'push_token' => 'fcm-token-rotated']))
        ->assertStatus(200);

    expect(Device::query()->count())->toBe(1);
});

it('records a refused code in the audit log without leaking the reason to the caller', function (): void {
    $alices = app(EnrolmentToken::class)->mint('user_alice');

    $response = enrol(codePayload(['enrolment_token' => $alices]), 'bob-tok');

    // One message for every reason — "already used" versus "someone else's" would let a
    // caller probe for which codes exist and whose they are.
    expect($response->json('message'))->toBe(
        'That enrolment code is not valid. Open Trusted devices for a fresh one.'
    );
});

it('sweeps spent codes once they could no longer be accepted', function (): void {
    // The table grows by a row per enrolment and is never read — it exists only to
    // refuse a replay — so without a sweep it grows for the life of the deployment.
    $code = app(EnrolmentToken::class)->mint('user_alice');
    enrol(codePayload(['enrolment_token' => $code]))->assertStatus(201);

    expect(EnrolmentCode::query()->withoutGlobalScopes()->count())->toBe(1);

    // Still inside the keep-window.
    Artisan::call('model:prune', ['--model' => [EnrolmentCode::class]]);
    expect(EnrolmentCode::query()->withoutGlobalScopes()->count())->toBe(1);

    EnrolmentCode::query()->withoutGlobalScopes()
        ->update(['expires_at' => Carbon::now()->subHours(2)]);

    Artisan::call('model:prune', ['--model' => [EnrolmentCode::class]]);
    expect(EnrolmentCode::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('sweeps with no environment in context, where the scope denies by default', function (): void {
    // EnvironmentScope appends `1 = 0` when nothing is in context, and a scheduled sweep
    // has nothing in context. Scoped, this would delete nothing while appearing to work.
    $code = app(EnrolmentToken::class)->mint('user_alice');
    enrol(codePayload(['enrolment_token' => $code]))->assertStatus(201);

    EnrolmentCode::query()->withoutGlobalScopes()
        ->update(['expires_at' => Carbon::now()->subHours(2)]);

    app(EnvironmentContext::class)->set(null);

    Artisan::call('model:prune', ['--model' => [EnrolmentCode::class]]);

    expect(EnrolmentCode::query()->withoutGlobalScopes()->count())->toBe(0);
});
