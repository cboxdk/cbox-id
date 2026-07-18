<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Identity\Testing\FakeWebAuthnVerifier;
use Cbox\Id\Kernel\Audit\Testing\FakeAuditLog;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountPasskeys;
use Cbox\Id\Platform\DatabaseAccountPasskeys;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;

/** Swap in a controllable verifier so the ceremony endpoints are testable server-side. */
function workspaceFakePasskeys(string $credentialId = 'cred_X', int $assertionSignCount = 3): AccountPasskeys
{
    $repo = new DatabaseAccountPasskeys(new FakeWebAuthnVerifier(credentialId: $credentialId, assertionSignCount: $assertionSignCount), new FakeAuditLog);
    app()->instance(AccountPasskeys::class, $repo);

    return $repo;
}

function workspaceCeremonyMember(): string
{
    return app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->member->id;
}

it('issues registration options for a signed-in member', function (): void {
    $memberId = workspaceCeremonyMember();
    workspaceFakePasskeys();

    $this->withSession([AccountAuth::SESSION_KEY => $memberId])
        ->postJson(route('workspace.passkeys.register.options'))
        ->assertOk()
        ->assertJsonStructure(['challenge', 'rp' => ['id', 'name'], 'user' => ['id', 'name']]);
});

it('completes register (options → create) and passwordless login in one session', function (): void {
    $memberId = workspaceCeremonyMember();
    $repo = workspaceFakePasskeys('cred_flow', assertionSignCount: 7);

    // Enrol: options then register, sharing the session so the challenge survives.
    $session = ['_token' => 'x', AccountAuth::SESSION_KEY => $memberId];
    $this->withSession($session)->postJson(route('workspace.passkeys.register.options'))->assertOk();
    // Seed a credential directly (the Fake ignores the assertion body anyway).
    $repo->register($memberId, 'chal', '{}', 'MacBook');
    expect($repo->forMember($memberId))->toHaveCount(1);

    // Passwordless login: a guest gets a challenge, then asserts the credential id.
    $this->postJson(route('workspace.passkeys.login.options'))->assertOk();
    $this->postJson(route('workspace.passkeys.login'), ['id' => 'cred_flow'])
        ->assertOk()
        ->assertJsonPath('redirect', route('workspace.home'));

    expect(session()->get(AccountAuth::SESSION_KEY))->toBe($memberId);
});

it('rejects a passkey login for an unregistered credential', function (): void {
    workspaceFakePasskeys();

    $this->postJson(route('workspace.passkeys.login.options'))->assertOk();
    $this->postJson(route('workspace.passkeys.login'), ['id' => 'never-registered'])
        ->assertStatus(422);

    expect(session()->get(AccountAuth::SESSION_KEY))->toBeNull();
});
