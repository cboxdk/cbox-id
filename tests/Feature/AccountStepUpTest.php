<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * The step-up in front of the Identity platform's machine credentials.
 *
 * This file used to be about a SECOND step-up. The account console had its own —
 * `WorkspaceSudo`, its own session key, its own `/workspace/sudo` screen — on the stated
 * ground that "a confirmation on one plane must never satisfy a step-up on the other".
 * There is one plane, so there is one step-up, and the pages that used to raise the
 * account one raise the console's. What that isolation was really protecting has not
 * moved: the ENVIRONMENT step-up is still separate, because it confirms authority over a
 * tenant rather than over yourself.
 *
 * The account-plane doors those tests also covered — the workspace sudo screen, the
 * workspace passkey ceremony, the member's own security page — are gone with the console
 * that carried them. `/sudo`, `/account` and the subject passkey ceremony are the ones
 * that remain, and they have their own coverage in SudoTest and PasskeyCeremonyTest.
 */
function signInMember(): string
{
    // The platform root FIRST. An account provisioned without one is in the
    // first-install bootstrap window: its members have no subject, and a member
    // with no subject has nothing to sign in.
    platformRootEnvironment();

    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    signInAsMember($result->owner->id);

    return $result->owner->id;
}

it('redirects account API key minting to sudo when not recently confirmed', function (): void {
    signInMember();

    Volt::test('console.api-keys')
        ->set('newKeyName', 'ci')
        ->call('createKey')
        ->assertRedirect(route('sudo'));

    expect(session()->get('sudo.intended'))->toBe(route('api-keys'));
});

it('mints an account API key once the step-up is confirmed', function (): void {
    signInMember();
    app(Sudo::class)->confirm();

    $component = Volt::test('console.api-keys')
        ->set('newKeyName', 'ci')
        ->set('newKeyRole', 'developer')
        ->call('createKey')
        ->assertHasNoErrors();

    // Read from view data, not get(): the plaintext key is a PROTECTED property so it is
    // never dehydrated into the wire snapshot. Asserting on get() would now pass on null.
    expect($component->viewData('freshKey'))->toBeString()->not->toBe('');
});

it('redirects environment key minting to sudo when not recently confirmed', function (): void {
    signInMember();

    Volt::test('console.environment-keys')
        ->set('newKeyName', 'ci')
        ->call('createKey')
        ->assertRedirect(route('sudo'));

    expect(session()->get('sudo.intended'))->toBe(route('environment-keys'));
});

/**
 * Revoking a machine credential is as consequential as minting one, and was ungated.
 *
 * A stolen but non-sudo session could not create persistence — issuing requires the
 * step-up — but it could destroy the keys that run provisioning and automation, which is
 * a denial of service the same session was otherwise held back from. Both key pages
 * gated create and neither gated revoke.
 */
it('requires the step-up to revoke an account key, not just to mint one', function (): void {
    signInMember();

    // Mint with sudo confirmed, then drop back to an unconfirmed session — the shape a
    // stolen cookie has.
    app(Sudo::class)->confirm();
    $keyId = Volt::test('console.api-keys')
        ->set('newKeyName', 'automation')
        ->call('createKey')
        ->viewData('keys')
        ->first()?->id;

    expect($keyId)->toBeString();

    session()->forget(Sudo::SESSION_KEY);

    Volt::test('console.api-keys')
        ->call('revokeKey', $keyId)
        ->assertRedirect(route('sudo'));

    expect(OrganizationApiKey::query()->whereKey($keyId)->value('revoked_at'))
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
    app(Sudo::class)->confirm();

    expect(app(Sudo::class)->confirmed())->toBeTrue();

    match ($transition) {
        'establish' => app(PlatformAuth::class)->establish(request(), 'subject-x', ['pwd']),
        'organization switch' => app(PlatformAuth::class)->switchOrganization(request(), 'org-elsewhere'),
    };

    expect(app(Sudo::class)->confirmed())
        ->toBeFalse("the step-up survived a {$transition}");
})->with(['establish', 'organization switch']);

/**
 * And the same through the account-membership door, which is where it bites hardest:
 * `establish()` is what an accepted invitation lands on — a sign-in where no password is
 * presented to THIS session at all.
 */
it('ends the step-up window when a member session is established', function (): void {
    $memberId = signInMember();

    app(Sudo::class)->confirm();
    expect(app(Sudo::class)->confirmed())->toBeTrue();

    app(AccountAuth::class)->establish($memberId);

    expect(app(Sudo::class)->confirmed())
        ->toBeFalse('a passwordless account sign-in inherited an open elevation');
});
