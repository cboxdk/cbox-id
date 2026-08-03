<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use App\Platform\WorkspaceSudo;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Models\AccountApiKey;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/** Provision an account and sign its owner into the workspace plane. */
function signInMember(): string
{
    // The platform root FIRST. An account provisioned without one is in the
    // first-install bootstrap window: its members have no subject, and a member
    // with no subject has nothing to sign in.
    platformRootEnvironment();

    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    signInAsMember($result->member);

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

    // Read from view data, not get(): the plaintext key is a PROTECTED property so it is
    // never dehydrated into the wire snapshot. Asserting on get() would now pass on null.
    expect($component->viewData('freshKey'))->toBeString()->not->toBe('');
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
    signInMember();

    $this->postJson(route('workspace.passkeys.register.options'))
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

/**
 * Revoking a machine credential is as consequential as minting one, and was ungated.
 *
 * A stolen but non-sudo workspace session could not create persistence — issuing requires
 * the step-up — but it could destroy the keys that run provisioning and automation, which
 * is a denial of service the same session was otherwise held back from. Both key pages
 * gated create and neither gated revoke.
 */
it('requires the step-up to revoke an account key, not just to mint one', function (): void {
    signInMember();

    // Mint with sudo confirmed, then drop back to an unconfirmed session — the shape a
    // stolen cookie has.
    app(WorkspaceSudo::class)->confirm();
    $keyId = Volt::test('workspace.api-keys')
        ->set('newKeyName', 'automation')
        ->call('createKey')
        ->viewData('keys')
        ->first()?->id;

    expect($keyId)->toBeString();

    session()->forget(WorkspaceSudo::SESSION_KEY);

    Volt::test('workspace.api-keys')
        ->call('revokeKey', $keyId)
        ->assertRedirect(route('workspace.sudo'));

    expect(AccountApiKey::query()->whereKey($keyId)->value('revoked_at'))
        ->toBeNull('a non-sudo session revoked a machine credential');
});

/**
 * A step-up attests that the person at this keyboard is who the session says. Every
 * transition below breaks that premise, and Laravel's `regenerate()` rotates the id while
 * PRESERVING the data — so a confirmation made before one carried straight through it.
 *
 * Not exploitable today, because the impersonation call guard refuses the actions a
 * carried-over sudo would unlock. That is the reason to fix it rather than the reason not
 * to: sudo is meant to be the independent second layer, and right now it is load-bearing
 * on whatever else happens to refuse first — which is precisely the single-layer
 * arrangement that let an impersonator reach the authorize endpoint.
 */
it('does not carry a step-up across a session transition', function (string $transition): void {
    signInMember();
    app(WorkspaceSudo::class)->confirm();

    expect(app(WorkspaceSudo::class)->confirmed())->toBeTrue();

    match ($transition) {
        'establish' => app(PlatformAuth::class)->establish(request(), 'subject-x', ['pwd']),
        'organization switch' => app(PlatformAuth::class)->switchOrganization(request(), 'org-elsewhere'),
    };

    expect(app(WorkspaceSudo::class)->confirmed())
        ->toBeFalse("the step-up survived a {$transition}");
})->with(['establish', 'organization switch']);
/**
 * And the same for the account plane, which is where it bites hardest: `establish()` is
 * reached by `adoptSubject()`, i.e. by magic-link redemption and federated landing —
 * sign-ins where no password is presented at all.
 */
it('ends the workspace step-up window when a member session is established', function (): void {
    $memberId = signInMember();

    app(WorkspaceSudo::class)->confirm();
    expect(app(WorkspaceSudo::class)->confirmed())->toBeTrue();

    // Re-establishing is what `adoptSubject()` does on a magic-link or federated
    // landing: a member session appears with no password ever presented.
    app(AccountAuth::class)->establish($memberId);

    expect(app(WorkspaceSudo::class)->confirmed())
        ->toBeFalse('a passwordless account sign-in inherited an open elevation');
});
