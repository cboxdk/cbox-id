<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\MfaFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

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
 * Worse, `$userId` was a plain public property. Livewire lets the browser set those on
 * every subsequent request, so the component could be retargeted after mount: open your
 * own page, post `userId=<somebody else>` alongside `setPassword`, and the page acts on
 * them.
 */
function takeoverTarget(): string
{
    return app(Subjects::class)->create('victim@acme.example', 'Victim')->id;
}

it('will not set a password without a fresh credential', function (): void {
    crudSetup();
    $victim = takeoverTarget();

    // No confirmEnvironmentStepUp() — this is an admin whose session is merely open.
    Volt::test('environment.users.show', ['user' => $victim])
        ->set('pwPassword', 'a-strong-unbreached-passphrase')
        ->set('pwReason', 'because I can')
        ->set('pwMode', 'permanent')
        ->set('pwDelivery', 'reveal')
        ->set('pwRevoke', 'none')
        ->set('pwExpiryHours', 0)
        ->call('setPassword');

    // The redirect to the step-up is the refusal; nothing was issued.
    expect(session()->has('newSecret'))->toBeFalse();
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

    Volt::test('environment.users.show', ['user' => $victim])->call('resetMfa');

    expect(MfaFactor::query()->where('user_id', $victim)->count())->toBe(1);
})->group('security');

it('will not mark an address verified without a fresh credential', function (): void {
    // Verified means recoverable: marking it is a takeover with one more step.
    crudSetup();
    $victim = takeoverTarget();

    Volt::test('environment.users.show', ['user' => $victim])->call('markVerified');

    expect(app(Subjects::class)->find($victim)?->emailVerified)->toBeFalse();
})->group('security');

it('cannot be retargeted at another account through the wire', function (): void {
    // #[Locked]: the route parameter decides whose account this page is, and Livewire
    // refuses an update that touches it. Without it, one crafted request re-points the
    // whole component — every action above included.
    crudSetup();
    $mine = takeoverTarget();
    $victim = app(Subjects::class)->create('other-victim@acme.example', 'Other')->id;

    // Caught rather than expect()->toThrow(): Pest reads a second argument to toThrow as
    // the message only for a concrete class, and Throwable is an interface — the
    // assertion silently became "the message contains the word Throwable", which the real
    // message does not. The message is the point here: it names the property, so a rename
    // that quietly drops the attribute fails rather than passing on some other error.
    $caught = null;

    try {
        Volt::test('environment.users.show', ['user' => $mine])->set('userId', $victim);
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught?->getMessage())->toContain('Cannot update locked property')
        ->and($caught?->getMessage())->toContain('userId');
})->group('security');

it('still does the work once the step-up is confirmed', function (): void {
    // The positive control. A guard that refuses everything passes all four above.
    crudSetup();
    $victim = takeoverTarget();

    confirmEnvironmentStepUp();

    Volt::test('environment.users.show', ['user' => $victim])->call('markVerified');

    expect(app(Subjects::class)->find($victim)?->emailVerified)->toBeTrue();
})->group('security');
