<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The breach check (HIBP) runs during signup — keep it offline.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:0')]);
});

it('blocks a bot signup (filled honeypot) when risk enforcement is on', function (): void {
    config(['risk.mode' => 'enforce']);

    Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Definitely Human')
        ->set('email', 'bot@example.com')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->set('website', 'http://spam.example')  // honeypot — a human never fills this
        ->call('register')
        ->assertHasErrors('email');

    expect(app(Subjects::class)->findByEmail('bot@example.com'))->toBeNull(); // no account created
});

it('allows the same signup in monitor mode (scores but does not block)', function (): void {
    config(['risk.mode' => 'monitor']);

    Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Definitely Human')
        ->set('email', 'bot@example.com')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->set('website', 'http://spam.example')
        ->call('register')
        ->assertHasNoErrors();

    expect(app(Subjects::class)->findByEmail('bot@example.com'))->not->toBeNull(); // created; only observed
});

it('lets a clean signup through under enforcement', function (): void {
    config(['risk.mode' => 'enforce']);

    Volt::test('auth.signup')
        ->set('organization', 'Acme')
        ->set('name', 'Dana Reeves')
        ->set('email', 'dana@example.com')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->set('website', '') // honeypot untouched
        ->call('register')
        ->assertHasNoErrors();

    expect(app(Subjects::class)->findByEmail('dana@example.com'))->not->toBeNull();
});
