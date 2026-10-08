<?php

declare(strict_types=1);

use App\Platform\LockoutDefaults;
use Cbox\Id\Identity\Contracts\LoginAttempts;
use Cbox\Id\Identity\Contracts\Subjects;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * SINCE LARAVEL-ID 1.22 AN EMPTY LOCKOUT THRESHOLD IS NOT "OFF".
 *
 * The sign-in rules page said "leave empty to disable lockout". After the upgrade an empty
 * threshold means the deployment default — ten failures in fifteen minutes — and only
 * `CBOX_ID_LOCKOUT_THRESHOLD=0` turns that off. The page now reads the default and says
 * which of the two is true here.
 */

it('reads the deployment default the way the framework does', function (): void {
    expect(LockoutDefaults::props())->toBe(['threshold' => 10, 'windowMinutes' => 15, 'durationMinutes' => 15]);

    // From an env var, everything is a string; nonsense is "no default", never a boot error.
    config()->set('cbox-id.lockout', ['threshold' => '5', 'window_minutes' => '30', 'duration_minutes' => 'soon']);

    expect(LockoutDefaults::props())->toBe(['threshold' => 5, 'windowMinutes' => 30, 'durationMinutes' => 15]);

    config()->set('cbox-id.lockout.threshold', '0');

    expect(LockoutDefaults::threshold())->toBeNull();
});

it('tells the sign-in rules page what an empty threshold means here', function (): void {
    crudSetup();

    expect(test()->get(route('environment.auth-policy'))->assertOk()->inertiaProps('lockoutDefault'))
        ->toBe(['threshold' => 10, 'windowMinutes' => 15, 'durationMinutes' => 15]);

    config()->set('cbox-id.lockout.threshold', 0);

    expect(test()->get(route('environment.auth-policy'))->assertOk()->inertiaProps('lockoutDefault.threshold'))
        ->toBeNull();
});

it('locks an account at the default with no sign-in rule naming a threshold', function (): void {
    crudSetup();
    $subject = app(Subjects::class)->create('guessed@acme.example', 'Guessed', 'a-strong-unbreached-passphrase');
    $attempts = app(LoginAttempts::class);

    foreach (range(1, 9) as $ignored) {
        expect($attempts->recordFailure($subject->id))->toBeFalse();
    }

    expect($attempts->recordFailure($subject->id))->toBeTrue()
        ->and($attempts->isLockedOut($subject->id))->toBeTrue();
})->group('security');
