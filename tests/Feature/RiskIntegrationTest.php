<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The breach check (HIBP) runs during signup — keep it offline.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:0')]);
});

it('blocks a bot signup (filled honeypot) when risk enforcement is on', function (): void {
    config(['risk.mode' => 'enforce']);

    // The honeypot filled in — a human never touches it, so a value there is evidence.
    attemptSignup([
        'name' => 'Definitely Human',
        'email' => 'bot@example.com',
        'website' => 'http://spam.example',
    ])->assertSessionHasErrors('email');

    expect(app(Subjects::class)->findByEmail('bot@example.com'))->toBeNull(); // no account created
});

it('allows the same signup in monitor mode (scores but does not block)', function (): void {
    config(['risk.mode' => 'monitor']);

    attemptSignup([
        'name' => 'Definitely Human',
        'email' => 'bot@example.com',
        'website' => 'http://spam.example',
    ])->assertSessionHasNoErrors();

    expect(app(Subjects::class)->findByEmail('bot@example.com'))->not->toBeNull(); // created; only observed
});

it('lets a clean signup through under enforcement', function (): void {
    config(['risk.mode' => 'enforce']);

    // Honeypot untouched, which is what the helper's default already is.
    attemptSignup(['name' => 'Dana Reeves', 'email' => 'dana@example.com'])
        ->assertSessionHasNoErrors();

    expect(app(Subjects::class)->findByEmail('dana@example.com'))->not->toBeNull();
});
