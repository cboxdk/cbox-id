<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Mfa\TotpAuthenticator;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function signIn(): string
{
    $subject = app(Subjects::class)->create('sudo@acme.test', 'Sudo User', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-sudo'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');

    return $subject->id;
}

it('redirects a sensitive action to sudo when not recently confirmed', function (): void {
    signIn();

    Volt::test('settings')
        ->call('regenerateRecoveryCodes')
        ->assertRedirect(route('sudo'));

    expect(session()->get('sudo.intended'))->toBe(route('settings'));
});

it('performs the sensitive action once sudo is confirmed', function (): void {
    signIn();
    app(Sudo::class)->confirm(); // fresh step-up

    // 2FA must be enabled for recovery codes; enable it inline first (sudo is
    // already fresh above, so the now-gated enable/confirm proceed).
    $component = Volt::test('settings')->call('enable');
    $secret = $component->get('secret');
    $component->set('code', app(TotpAuthenticator::class)->codeAt($secret, time()))->call('confirm');

    $component->call('regenerateRecoveryCodes')->assertHasNoErrors();

    expect($component->get('recoveryCodes'))->toHaveCount(10);
});

it('sends TOTP enrollment through sudo when not recently confirmed', function (): void {
    signIn();

    // enrollTotp overwrites any existing secret — it must not run from a stale
    // session. Without a fresh step-up, the action redirects to re-auth.
    Volt::test('settings')->call('enable')->assertRedirect(route('sudo'));
});

it('sends provider unlink through sudo when not recently confirmed', function (): void {
    $id = signIn();
    app(Subjects::class)->link($id, new FederatedPrincipal('social:google', 'google|1'));

    Volt::test('settings')->call('unlinkProvider', 'google')->assertRedirect(route('sudo'));
});

it('refuses to unlink a user\'s only sign-in method', function (): void {
    // A social-only account: no password, no passkey, one linked provider.
    $subject = app(Subjects::class)->create('social-only@acme.test', 'Social Only');
    $org = app(Organizations::class)->create(new NewOrganization('Acme2', 'acme-social'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['sso']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');
    app(Subjects::class)->link($subject->id, new FederatedPrincipal('social:google', 'google|1'));
    app(Sudo::class)->confirm();

    Volt::test('settings')
        ->call('unlinkProvider', 'google')
        ->assertHasErrors('unlink');

    // The identity is still linked — the guard blocked the lockout.
    expect(app(Subjects::class)->linkedIdentities($subject->id))->toHaveCount(1);
});

it('allows unlinking when another sign-in method remains', function (): void {
    $id = signIn(); // signIn() creates the account WITH a password
    app(Subjects::class)->link($id, new FederatedPrincipal('social:google', 'google|1'));
    app(Sudo::class)->confirm();

    Volt::test('settings')
        ->call('unlinkProvider', 'google')
        ->assertHasNoErrors();

    expect(app(Subjects::class)->linkedIdentities($id))->toBeEmpty();
});

it('confirms sudo with the correct password and clears it otherwise', function (): void {
    signIn();

    Volt::test('auth.sudo')
        ->set('password', 'wrong')
        ->call('confirm')
        ->assertHasErrors('password');

    expect(app(Sudo::class)->confirmed())->toBeFalse();

    Volt::test('auth.sudo')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(app(Sudo::class)->confirmed())->toBeTrue();
});
