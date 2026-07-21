<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Sudo;
use App\Platform\WorkspaceSudo;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/** Provision an account and sign its owner into the workspace plane. */
function signInMember(): string
{
    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    session()->put(AccountAuth::SESSION_KEY, $result->member->id);

    return $result->member->id;
}

it('redirects account API key minting to sudo when not recently confirmed', function (): void {
    signInMember();

    Volt::test('workspace.api-keys')
        ->set('newKeyName', 'ci')
        ->call('createKey')
        ->assertRedirect(route('workspace.sudo'));

    expect(session()->get('workspace.sudo.intended'))->toBe(route('workspace.api-keys'));
});

it('mints an account API key once workspace sudo is confirmed', function (): void {
    signInMember();
    app(WorkspaceSudo::class)->confirm();

    $component = Volt::test('workspace.api-keys')
        ->set('newKeyName', 'ci')
        ->set('newKeyRole', 'developer')
        ->call('createKey')
        ->assertHasNoErrors();

    expect($component->get('freshKey'))->toBeString()->not->toBe('');
});

it('gates MFA recovery regeneration behind sudo', function (): void {
    signInMember();

    Volt::test('workspace.security')
        ->call('regenerateRecoveryCodes')
        ->assertRedirect(route('workspace.sudo'));
});

it('confirms workspace sudo with the correct password and rejects a wrong one', function (): void {
    signInMember();

    Volt::test('workspace.sudo')
        ->set('password', 'wrong')
        ->call('confirm')
        ->assertHasErrors('password');
    expect(app(WorkspaceSudo::class)->confirmed())->toBeFalse();

    Volt::test('workspace.sudo')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('confirm')
        ->assertHasNoErrors();
    expect(app(WorkspaceSudo::class)->confirmed())->toBeTrue();
});

it('gates workspace passkey enrolment behind sudo at the HTTP layer', function (): void {
    $id = signInMember();

    $this->withSession([AccountAuth::SESSION_KEY => $id])
        ->postJson(route('workspace.passkeys.register.options'))
        ->assertStatus(403)
        ->assertJsonPath('sudo', route('workspace.sudo'));
});

it('does not require sudo for a subject-plane confirmation (planes are isolated)', function (): void {
    signInMember();
    // Confirming the SUBJECT-plane sudo must NOT satisfy the account plane.
    app(Sudo::class)->confirm();

    expect(app(WorkspaceSudo::class)->confirmed())->toBeFalse();

    Volt::test('workspace.security')
        ->call('regenerateRecoveryCodes')
        ->assertRedirect(route('workspace.sudo'));
});
