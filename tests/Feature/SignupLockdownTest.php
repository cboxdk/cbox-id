<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('shows the create-account link on sign-in only when signup is open', function (): void {
    config(['cbox-id.signup.mode' => 'open']);
    $this->get(route('login'))->assertSee('Create one');

    config(['cbox-id.signup.mode' => 'invite_only']);
    $this->get(route('login'))->assertDontSee('Create one');
});
