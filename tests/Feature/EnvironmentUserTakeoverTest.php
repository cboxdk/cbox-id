<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\MfaFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * THE MOST COMPLETE TAKEOVER THE CONSOLE OFFERS, AND IT HAD NO STEP-UP.
 *
 * /admin/users/{user} can replace any account's password and — with `reveal` — hand the
 * plaintext straight back to the administrator. The vault and the legacy-login page two
 * screens away have demanded a fresh credential since the planes merged, on the explicit
 * reasoning that the more privileged door should not be the one without one. This was
 * that door: an admin's browser left open on a desk was the whole attack.
 *
 * Worse, the target was a plain public component property. Livewire lets the browser set
 * those on every subsequent request, so the page could be retargeted after mount: open
 * your own page, post `userId=<somebody else>` alongside `setPassword`, and the page acts
 * on them. It is a route parameter now, which cannot be retargeted at all — but the same
 * question has a new shape, and the last test here asks it.
 */
function takeoverTarget(): string
{
    return app(Subjects::class)->create('victim@acme.example', 'Victim')->id;
}

it('will not set a password without a fresh credential', function (): void {
    crudSetup();
    $victim = takeoverTarget();

    // No confirmEnvironmentStepUp() — this is an admin whose session is merely open.
    setUserPassword($victim, ['mode' => 'permanent', 'revoke' => 'nothing', 'expiryHours' => 0])
        ->assertRedirect(route('environment.sudo'));

    // The redirect to the step-up is the refusal; nothing was issued, and — the part that
    // matters — the victim's credential is untouched.
    expect(session()->has('issuedPassword'))->toBeFalse()
        ->and(app(Subjects::class)->verifyPassword($victim, 'a-strong-unbreached-passphrase'))->toBeFalse();
})->group('security');

it('will not reset two-factor without a fresh credential', function (): void {
    crudSetup();
    $victim = takeoverTarget();
    MfaFactor::query()->create([
        'user_id' => $victim,
        'type' => 'totp',
        'secret_encrypted' => 'sealed',
        'confirmed_at' => now(),
    ]);

    test()->post(route('environment.users.mfa', $victim))
        ->assertRedirect(route('environment.sudo'));

    expect(MfaFactor::query()->where('user_id', $victim)->count())->toBe(1);
})->group('security');

it('will not mark an address verified without a fresh credential', function (): void {
    // Verified means recoverable: marking it is a takeover with one more step.
    crudSetup();
    $victim = takeoverTarget();

    test()->post(route('environment.users.verify', $victim))
        ->assertRedirect(route('environment.sudo'));

    expect(app(Subjects::class)->find($victim)?->emailVerified)->toBeFalse();
})->group('security');

/**
 * WHOSE ACCOUNT THIS IS, is the URL's answer and nothing else's.
 *
 * The Livewire shape of this bug was a public property the browser could re-set after
 * mount. The route parameter that replaced it cannot be retargeted — but the password rule
 * validates against THE USER'S OWN POLICY, so a body field claiming to name a different
 * user would be the same bug wearing different clothes: the password checked against one
 * person's rules and written to another's account.
 *
 * So the request reads the target from the route, and this holds that a body field of the
 * same name changes nothing about who is acted on.
 */
it('acts on the user in the URL, never one named in the body', function (): void {
    crudSetup();
    $mine = takeoverTarget();
    $victim = app(Subjects::class)->create('other-victim@acme.example', 'Other')->id;

    confirmEnvironmentStepUp();

    setUserPassword($mine, ['user' => $victim, 'userId' => $victim])
        ->assertSessionHasNoErrors();

    expect(app(Subjects::class)->verifyPassword($mine, 'a-strong-unbreached-passphrase'))->toBeTrue()
        ->and(app(Subjects::class)->verifyPassword($victim, 'a-strong-unbreached-passphrase'))->toBeFalse();
})->group('security');

it('still does the work once the step-up is confirmed', function (): void {
    // The positive control. A guard that refuses everything passes all four above.
    crudSetup();
    $victim = takeoverTarget();

    confirmEnvironmentStepUp();

    test()->post(route('environment.users.verify', $victim))->assertSessionHasNoErrors();

    expect(app(Subjects::class)->find($victim)?->emailVerified)->toBeTrue();
})->group('security');
