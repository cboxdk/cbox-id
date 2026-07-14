<?php

declare(strict_types=1);

use App\Mail\MagicLinkMail;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

it('serves the signup page when signup is open', function (): void {
    config(['cbox-id.signup.mode' => 'open']);

    $this->get(route('signup'))->assertOk();
});

it('redirects signup to sign-in when invite-only', function (): void {
    config(['cbox-id.signup.mode' => 'invite_only']);

    $this->get(route('signup'))->assertRedirect(route('login'));
});

it('redirects signup to sign-in when closed', function (): void {
    config(['cbox-id.signup.mode' => 'closed']);

    $this->get(route('signup'))->assertRedirect(route('login'));
});

it('forbids the register action if signup closes after the form was reached', function (): void {
    config(['cbox-id.signup.mode' => 'open']);

    $component = Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Ada Lovelace')
        ->set('email', 'ada@acme.test')
        ->set('password', 'a-strong-unbreached-passphrase');

    // Signup closes before submit — the guard must still refuse.
    config(['cbox-id.signup.mode' => 'closed']);

    $component->call('register')->assertForbidden();
});

it('does not mint an account via a magic link for an unknown email when signup is closed', function (): void {
    config(['cbox-id.signup.mode' => 'closed']);
    Mail::fake();

    // Redeeming a magic link would create the account (findByEmail ?? create), so
    // an unqualified link is a signup bypass. Under closed signup, an unknown email
    // must get NO link and NO account — while still seeing the neutral confirmation.
    Volt::test('auth.login')
        ->set('email', 'ghost@nowhere.test')
        ->call('sendMagicLink')
        ->assertSet('magicSent', true);

    Mail::assertNothingSent();
    expect(app(Subjects::class)->findByEmail('ghost@nowhere.test'))->toBeNull();
});

it('still sends a magic link to an existing account when signup is closed', function (): void {
    config(['cbox-id.signup.mode' => 'closed']);
    Mail::fake();
    app(Subjects::class)->create('member@acme.test', 'Member', 'a-strong-unbreached-passphrase');

    Volt::test('auth.login')
        ->set('email', 'member@acme.test')
        ->call('sendMagicLink')
        ->assertSet('magicSent', true);

    Mail::assertSent(MagicLinkMail::class);
});

it('shows the create-account link on sign-in only when signup is open', function (): void {
    config(['cbox-id.signup.mode' => 'open']);
    $this->get(route('login'))->assertSee('Create one');

    config(['cbox-id.signup.mode' => 'invite_only']);
    $this->get(route('login'))->assertDontSee('Create one');
});
