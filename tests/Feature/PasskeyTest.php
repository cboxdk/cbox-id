<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\UnknownCredential;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/**
 * A controllable Passkeys stand-in: the framework already tests the real WebAuthn
 * verifier against a software authenticator, so here we verify only the HTTP +
 * session bridging in the app's controller.
 */
function fakePasskeys(?string $authenticateAs): void
{
    app()->instance(Passkeys::class, new class($authenticateAs) implements Passkeys
    {
        public function __construct(private readonly ?string $authenticateAs) {}

        public function register(string $userId, string $challenge, string $clientResponseJson, ?string $name = null): WebAuthnCredential
        {
            return new WebAuthnCredential(['user_id' => $userId, 'credential_id' => 'cred_'.$userId, 'name' => $name]);
        }

        public function authenticate(string $credentialId, string $challenge, string $clientResponseJson): string
        {
            return $this->authenticateAs ?? throw new UnknownCredential('none');
        }

        public function credentialById(string $credentialId): ?WebAuthnCredential
        {
            return null;
        }
    });
}

it('requires authentication to enrol a passkey', function () {
    $this->postJson('/passkeys/register/options')->assertRedirect(route('login'));
});

it('issues registration options for a signed-in subject', function () {
    [$subject] = accountWithOrg('pk@acme.test');
    $this->withSession([PlatformAuth::SESSION_KEY => app(SessionManager::class)->start($subject->id, null, ['pwd'])->id]);

    $this->postJson('/passkeys/register/options')
        ->assertOk()
        ->assertJsonPath('rp.id', 'localhost')
        ->assertJsonStructure(['challenge', 'user' => ['id', 'name'], 'pubKeyCredParams']);
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

/**
 * @return array{0: Subject, 1: Organization}
 */
function accountWithOrg(string $email): array
{
    $subject = app(Subjects::class)->create($email, 'Holder', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.substr(md5($email), 0, 6)));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');

    return [$subject, $org];
}
