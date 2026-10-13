<?php

declare(strict_types=1);

use App\Actions\Account\RenamePasskey;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\ActionVia;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Console\ConsoleScope;
use App\Platform\Passkeys\PasskeyName;
use App\Platform\Passkeys\PasskeyProviders;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Contracts\WebAuthnVerifier;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\Testing\FakeWebAuthnVerifier;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Crypto\Support\Base64Url;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeDelegatedTokens;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Naming a passkey — on creation, and renaming it later, at every door.
|--------------------------------------------------------------------------
|
| There was no way to name one: every passkey was "<Name>'s device", and two of them were
| indistinguishable on the one page that exists to remove the right one. A new passkey now
| starts with the name of the password manager or authenticator that made it, the person
| can keep or change that, and `account.passkeys.rename` renames one later — from My
| account, the API, MCP and the CLI alike, held to one rule and audited.
*/

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/** A token $subjectId delegated, for the account API. */
function passkeyNamingToken(string $subjectId, array $scopes = ['account:sign_in:write']): string
{
    $bearer = 'pk-naming-'.$subjectId.'-'.implode(',', $scopes);
    FakeDelegatedTokens::install()->person($bearer, $subjectId, $scopes);

    return $bearer;
}

/** A passkey of $subjectId's, in the platform root where account members live. */
function passkeyOf(string $subjectId, string $name = 'Passkey', string $credentialId = 'cred-a'): WebAuthnCredential
{
    return app(PlatformRoot::class)->run(fn (): WebAuthnCredential => WebAuthnCredential::query()->create([
        'user_id' => $subjectId,
        'credential_id' => $credentialId,
        'public_key' => 'pk',
        'name' => $name,
        'sign_count' => 0,
    ]));
}

function passkeyNameOf(WebAuthnCredential $passkey): ?string
{
    return app(PlatformRoot::class)->run(fn (): mixed => WebAuthnCredential::query()->whereKey($passkey->id)->value('name'));
}

/**
 * A registration response the way a browser posts it, with an attestation object whose
 * authenticator data names `$aaguid` — the only part the default name reads.
 */
function registrationJson(?string $aaguid, string $suggested = 'Passkey on Chrome · macOS'): array
{
    $guid = $aaguid === null ? str_repeat("\0", 16) : (string) hex2bin(str_replace('-', '', $aaguid));
    // rpIdHash(32) ‖ flags UP|UV|AT ‖ signCount(4) ‖ AAGUID(16) ‖ credentialIdLength(2)
    $authData = str_repeat("\0", 32).chr(0x45)."\0\0\0\0".$guid."\0\0";

    // {"fmt": "none", "attStmt": {}, "authData": h'…'} in CBOR, by hand.
    $cbor = "\xA3"."\x63fmt"."\x64none"."\x67attStmt"."\xA0"."\x68authData"."\x58".chr(strlen($authData)).$authData;

    return [
        'name' => $suggested,
        'id' => 'cred-browser',
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => Base64Url::encode('{}'),
            'attestationObject' => Base64Url::encode($cbor),
            'transports' => ['internal'],
        ],
    ];
}

/** Enrol through the real endpoints, with a verifier that trusts the fake authenticator. */
function enrolPasskey(array $body): TestResponse
{
    app()->instance(WebAuthnVerifier::class, new FakeWebAuthnVerifier('cred-'.md5(json_encode($body) ?: '')));
    app(Sudo::class)->confirm();

    test()->postJson('/passkeys/register/options')->assertOk();

    return test()->postJson('/passkeys/register', $body);
}

it('names a new passkey after the password manager that made it', function (string $aaguid, string $provider): void {
    ['subjectId' => $me] = provisionAccount();
    signInAsMember($me);

    enrolPasskey(registrationJson($aaguid))
        ->assertOk()
        ->assertJsonPath('passkey.name', $provider)
        ->assertJsonPath('passkey.renameHref', fn (string $href): bool => str_contains($href, '/account/passkeys/'));
})->with([
    'iCloud Keychain' => ['fbfc3007-154e-4ecc-8c0b-6e020557d7bd', 'iCloud Keychain'],
    'Google Password Manager' => ['ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4', 'Google Password Manager'],
    '1Password' => ['bada5566-a7aa-401f-bd96-45619a55120d', '1Password'],
    'Windows Hello' => ['08987058-cadc-4b81-b6e1-30de50dcbe96', 'Windows Hello'],
]);

it('falls back to the page\'s browser-and-system name, then to "Passkey"', function (): void {
    ['subjectId' => $me] = provisionAccount();
    signInAsMember($me);

    // An authenticator that sends zeros — and one this list does not know.
    enrolPasskey(registrationJson(null, 'Passkey on Firefox · Linux'))
        ->assertOk()
        ->assertJsonPath('passkey.name', 'Passkey on Firefox · Linux');

    enrolPasskey(registrationJson('00112233-4455-6677-8899-aabbccddeeff', 'Passkey on Safari · iPhone'))
        ->assertOk()
        ->assertJsonPath('passkey.name', 'Passkey on Safari · iPhone');

    // A suggestion that is not a name a passkey may carry is not used as one.
    enrolPasskey(registrationJson(null, "line one\nline two"))
        ->assertOk()
        ->assertJsonPath('passkey.name', PasskeyName::FALLBACK);
});

it('reads no AAGUID out of something that is not an attestation, and never throws', function (): void {
    expect(PasskeyProviders::aaguidFrom('not json'))->toBeNull()
        ->and(PasskeyProviders::aaguidFrom('{"response":{"attestationObject":"!!"}}'))->toBeNull()
        ->and(PasskeyProviders::aaguidFrom(json_encode(registrationJson('fbfc3007-154e-4ecc-8c0b-6e020557d7bd')) ?: ''))
        ->toBe('fbfc3007-154e-4ecc-8c0b-6e020557d7bd');
});

it('renames a passkey through the API, for the person behind the token only, and audits it', function (): void {
    ['subjectId' => $me] = provisionAccount();
    ['subjectId' => $them] = provisionAccount('them@other.example');

    $mine = passkeyOf($me, 'iCloud Keychain');
    $theirs = passkeyOf($them, 'Theirs', 'cred-theirs');
    $token = passkeyNamingToken($me);

    $this->withToken($token)->patchJson("/api/v1/me/passkeys/{$mine->id}", ['name' => '  Work laptop  '])
        ->assertOk()
        ->assertJsonPath('data.id', $mine->id)
        ->assertJsonPath('data.name', 'Work laptop')
        ->assertJsonStructure(['data' => ['id', 'name', 'created_at', 'last_used_at']]);

    // Somebody else's is not found, and stays as it was.
    $this->withToken($token)->patchJson("/api/v1/me/passkeys/{$theirs->id}", ['name' => 'Mine now'])->assertNotFound();

    // A token without the scope gets nothing.
    $this->withToken(passkeyNamingToken($me, ['account:profile:write']))
        ->patchJson("/api/v1/me/passkeys/{$mine->id}", ['name' => 'Nope'])
        ->assertForbidden();

    expect(passkeyNameOf($mine))->toBe('Work laptop')
        ->and(passkeyNameOf($theirs))->toBe('Theirs');

    $entry = app(PlatformRoot::class)->run(fn () => AuditEntry::query()->where('action', 'user.passkey_renamed')->sole());

    expect($entry->actor_id)->toBe($me)
        ->and($entry->context['from'] ?? null)->toBe('iCloud Keychain')
        ->and($entry->context['to'] ?? null)->toBe('Work laptop');
})->group('security');

it('holds a name to one rule at every door', function (string $name): void {
    ['subjectId' => $me] = provisionAccount();
    $mine = passkeyOf($me, 'Original');

    $this->withToken(passkeyNamingToken($me))
        ->patchJson("/api/v1/me/passkeys/{$mine->id}", ['name' => $name])
        ->assertStatus(422);

    expect(passkeyNameOf($mine))->toBe('Original');
})->with([
    'empty' => [''],
    'only spaces' => ['   '],
    'too long' => [str_repeat('x', 65)],
    'a line break' => ["Laptop\nPhone"],
    'a control character' => ["Laptop\x07"],
    'a direction override' => ["Laptop \u{202E}txt.exe"],
]);

it('accepts sixty-four characters, and letters from any script', function (): void {
    ['subjectId' => $me] = provisionAccount();
    $mine = passkeyOf($me);
    $token = passkeyNamingToken($me);

    $this->withToken($token)->patchJson("/api/v1/me/passkeys/{$mine->id}", ['name' => str_repeat('é', 64)])->assertOk();
    $this->withToken($token)->patchJson("/api/v1/me/passkeys/{$mine->id}", ['name' => 'Søren\'s <b>YubiKey</b> 🔑'])->assertOk();

    // Stored as text, exactly as typed — escaping is the renderer's, wherever it is drawn.
    expect(passkeyNameOf($mine))->toBe('Søren\'s <b>YubiKey</b> 🔑');
});

it('renames from My account with no step-up, as the console runs the same action', function (): void {
    ['subjectId' => $me] = provisionAccount();
    $mine = passkeyOf($me, 'Passkey on Chrome · macOS');
    signInAsMember($me);

    // No `Sudo::confirm()`: a label grants nothing, so it is not behind the step-up.
    inertiaRequest(fn (): TestResponse => $this->from(route('account'))
        ->patch(route('account.passkeys.update', $mine->id), ['name' => 'Desk Mac']))
        ->assertRedirect(route('account'))
        ->assertSessionHasNoErrors();

    expect(passkeyNameOf($mine))->toBe('Desk Mac');

    // A refusal lands on the dialog's field.
    inertiaRequest(fn (): TestResponse => $this->from(route('account'))
        ->patch(route('account.passkeys.update', $mine->id), ['name' => str_repeat('x', 65)]))
        ->assertSessionHasErrors('passkeyName');

    // Somebody else's id is a 404, not a refusal.
    ['subjectId' => $them] = provisionAccount('them-console@other.example');
    $theirs = passkeyOf($them, 'Theirs', 'cred-theirs-console');

    inertiaRequest(fn (): TestResponse => $this->from(route('account'))
        ->patch(route('account.passkeys.update', $theirs->id), ['name' => 'Mine']))
        ->assertNotFound();
})->group('security');

it('refuses a console principal that is not the person', function (): void {
    ['subjectId' => $me] = provisionAccount();
    $mine = passkeyOf($me);

    // Signed in as nobody: the console principal has no person to act for.
    expect(fn () => app(ActionRunner::class)->run(
        RenamePasskey::class,
        new ConsoleSessionPrincipal(app(ConsoleScope::class)),
        ['passkey_id' => $mine->id, 'name' => 'x'],
        via: ActionVia::Console,
    ))->toThrow(AuthorizationException::class);
})->group('security');

it('lists each passkey with its name, when it was added and when it last signed in', function (): void {
    ['subjectId' => $me] = provisionAccount();
    $mine = passkeyOf($me, 'YubiKey', 'cred-listed');
    signInAsMember($me);

    $passkeys = fn (): array => (array) $this->get(route('account'))->assertOk()->inertiaProps('passkeys');

    expect($passkeys()[0])
        ->toMatchArray(['id' => $mine->id, 'name' => 'YubiKey', 'lastUsed' => null])
        ->toHaveKeys(['added', 'renameHref', 'removeHref']);

    // Signing in with it stamps it — through the real service and the decorator around
    // it, behind a verifier that trusts the fake authenticator. Signed out first: the
    // sign-in door sends somebody already signed in on their way.
    app()->instance(WebAuthnVerifier::class, new FakeWebAuthnVerifier('cred-listed', assertionSignCount: 0));
    app()->forgetInstance(Passkeys::class);
    $this->flushSession();
    $this->postJson('/passkeys/login/options')->assertOk();
    $this->postJson('/passkeys/login', [
        'id' => 'cred-listed',
        'type' => 'public-key',
        'response' => ['clientDataJSON' => 'x', 'authenticatorData' => 'x', 'signature' => 'x'],
    ])->assertOk();

    signInAsMember($me);

    expect($passkeys()[0]['lastUsed'])->toBeString();
});

it('normalizes a name the same way for the default and for a rename', function (): void {
    expect(PasskeyName::problem('ok'))->toBeNull()
        ->and(PasskeyName::problem(''))->toBe('Give the passkey a name.')
        ->and(PasskeyName::normalize('  fine  '))->toBe('fine')
        ->and(PasskeyName::normalize("bad\tname"))->toBeNull()
        ->and(PasskeyName::normalize(null))->toBeNull();
});
