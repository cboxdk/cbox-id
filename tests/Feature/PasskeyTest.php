<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Models\WebAuthnCredential;

it('requires authentication to enrol a passkey', function () {
    // 401, not a redirect. This asked for JSON, and answering a JSON caller with a 302
    // to an HTML sign-in page gives it nothing it can act on — which is the same defect
    // that made an expired console silently stop working: Livewire followed the redirect,
    // got a 200 of login HTML, and had no failure to report.
    $this->postJson('/passkeys/register/options')
        ->assertStatus(401)
        ->assertJsonPath('redirect', route('login'));
});

it('issues registration options for a signed-in subject with a fresh step-up', function () {
    [$subject] = accountWithOrg('pk@acme.test');
    $this->withSession([
        PlatformAuth::SESSION_KEY => app(SessionManager::class)->start($subject->id, null, ['pwd'])->id,
        // Adding a passkey is gated behind sudo — confirm a fresh step-up window.
        'cbox.sudo_confirmed_at' => time(),
    ]);

    $this->postJson('/passkeys/register/options')
        ->assertOk()
        ->assertJsonPath('rp.id', 'localhost')
        ->assertJsonStructure(['challenge', 'user' => ['id', 'name'], 'pubKeyCredParams']);
});

it('refuses to enrol a passkey without a fresh sudo window', function () {
    [$subject] = accountWithOrg('pk-nosudo@acme.test');
    // Signed in, but no fresh step-up — a hijacked/stale session must not be able
    // to plant a persistent credential.
    $this->withSession([PlatformAuth::SESSION_KEY => app(SessionManager::class)->start($subject->id, null, ['pwd'])->id])
        ->postJson('/passkeys/register/options')
        ->assertStatus(403)
        ->assertJsonPath('sudo', route('sudo'));

    expect(WebAuthnCredential::query()->where('user_id', $subject->id)->exists())->toBeFalse();
});

it('issues login options with a challenge', function () {
    $this->postJson('/passkeys/login/options')
        ->assertOk()
        ->assertJsonPath('rpId', 'localhost')
        ->assertJsonStructure(['challenge', 'allowCredentials']);
});

it('signs in with a verified passkey assertion and starts a session', function () {
    [$subject, $org] = accountWithOrg('holder@acme.test');
    fakePasskeys($subject->id);

    $this->withSession(['passkey.auth_challenge' => ['c' => base64_encode(random_bytes(32)), 'exp' => time() + 120]])
        ->postJson('/passkeys/login', [
            'id' => 'cred_'.$subject->id,
            'type' => 'public-key',
            'response' => ['clientDataJSON' => 'x', 'authenticatorData' => 'x', 'signature' => 'x'],
        ])
        ->assertOk()
        ->assertJsonPath('redirect', route('dashboard'));

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeTrue();
});

it('rejects a passkey assertion whose challenge has expired', function () {
    [$subject] = accountWithOrg('expired@acme.test');
    fakePasskeys($subject->id);

    // A challenge issued more than its TTL ago must not be accepted.
    $this->withSession(['passkey.auth_challenge' => ['c' => base64_encode(random_bytes(32)), 'exp' => time() - 1]])
        ->postJson('/passkeys/login', [
            'id' => 'cred_'.$subject->id,
            'type' => 'public-key',
            'response' => ['clientDataJSON' => 'x', 'authenticatorData' => 'x', 'signature' => 'x'],
        ])
        ->assertStatus(422);

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
});

it('rejects a passkey assertion with no stored challenge', function () {
    fakePasskeys('someone');

    $this->postJson('/passkeys/login', ['id' => 'cred_x', 'response' => []])
        ->assertStatus(422);

    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
});
