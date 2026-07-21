<?php

declare(strict_types=1);

use App\Rules\NotBreached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * The breached-password screen was applied at signup, invite acceptance and the
 * workspace reset — but NOT at the subject-plane reset, which is precisely the flow an
 * attacker with a stolen reset token uses, and the one where a user is most likely to
 * reach for a password they have used before.
 *
 * Testing the instance would only prove that one flow. This tests the INVARIANT: every
 * component that accepts a password screens it. A newly added flow that forgets fails
 * here rather than shipping quietly.
 */
it('screens every password-accepting flow against the breach corpus', function (): void {
    $components = glob(resource_path('views/livewire/**/*.blade.php'), GLOB_BRACE)
        + glob(resource_path('views/livewire/*.blade.php'));

    /** @var list<string> $unscreened */
    $unscreened = [];

    foreach ($components as $file) {
        $source = file_get_contents($file) ?: '';

        // A component "accepts a password" when it validates one into a real credential.
        $setsPassword = preg_match("/'password'\s*=>\s*\[/", $source) === 1
            || str_contains($source, "#[Validate('required|min:12");

        if (! $setsPassword) {
            continue;
        }

        // Sign-IN validates a password without setting one; screening there would leak
        // breach status for an existing credential and block a legitimate login.
        if (str_contains($source, 'AttemptOutcome') || str_contains($source, '->attempt(')) {
            continue;
        }

        // Match the RULE BEING APPLIED, not the word appearing. Checking for
        // 'NotBreached' alone matched the `use` import (and this file's own comments),
        // so the assertion passed even with the rule deleted from the rules array —
        // a vacuous test that proved nothing. Caught by deleting the rule and watching
        // it stay green.
        if (! str_contains($source, 'new NotBreached')) {
            $unscreened[] = str_replace(resource_path('views/livewire/'), '', $file);
        }
    }

    expect($unscreened)->toBe(
        [],
        'These flows set a password without screening it against the breach corpus: '
        .implode(', ', $unscreened)
    );
});

it('rejects a known-breached password on the subject-plane reset', function (): void {
    // k-anonymity range response: the suffix of sha1('password') with a hit count.
    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response("1E4C9B93F3F0682250B6CF8331B7EE68FD8:37359\r\n", 200),
    ]);

    $validator = validator(
        ['password' => 'password'],
        ['password' => [new NotBreached]],
    );

    expect($validator->fails())->toBeTrue();
});
