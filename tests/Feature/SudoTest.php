<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Mfa\TotpAuthenticator;
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

    // 2FA must be enabled for recovery codes; enable it inline first (also sudo-gated? no — enable isn't).
    $component = Volt::test('settings')->call('enable');
    $secret = $component->get('secret');
    $component->set('code', app(TotpAuthenticator::class)->codeAt($secret, time()))->call('confirm');

    $component->call('regenerateRecoveryCodes')->assertHasNoErrors();

    expect($component->get('recoveryCodes'))->toHaveCount(10);
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
