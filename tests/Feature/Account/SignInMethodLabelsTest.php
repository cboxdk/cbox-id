<?php

declare(strict_types=1);

use App\Platform\Locale\SignInMethodLabels;

/**
 * A session's `amr` codes are the protocol's words. A person reads "Password" and
 * "Approved on your phone", never "pwd device mfa".
 */
it('names each method in words and never prints a code', function (): void {
    expect(SignInMethodLabels::for(['pwd']))->toBe(['Password'])
        ->and(SignInMethodLabels::for(['pwd', 'device', 'mfa']))->toBe(['Password', 'Approved on your phone'])
        ->and(SignInMethodLabels::for(['pwd', 'otp', 'mfa']))->toBe(['Password', 'Authenticator app code'])
        ->and(SignInMethodLabels::for(['passkey']))->toBe(['Passkey'])
        // `mfa` alone is the only thing said about the second step, so it is said.
        ->and(SignInMethodLabels::for(['pwd', 'mfa']))->toBe(['Password', 'Two-step verification'])
        // A code nobody named is left out, not shown raw.
        ->and(SignInMethodLabels::for(['pwd', 'hwk']))->toBe(['Password'])
        ->and(SignInMethodLabels::for(null))->toBe([]);
});

it('speaks the reader\'s language', function (): void {
    app()->setLocale('da');

    expect(SignInMethodLabels::for(['pwd', 'device', 'mfa']))->toBe(['Adgangskode', 'Godkendt på din telefon']);
});
