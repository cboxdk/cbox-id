<?php

declare(strict_types=1);

use App\Rules\NotBreached;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\PolicyViolation;
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
    // A RECURSIVE WALK, because `glob('**/*.blade.php')` is not one. PHP's `**` is an
    // ordinary wildcard matching a SINGLE path segment, so this pair saw 37 of the 92
    // components under `livewire/` — everything two directories deep was invisible,
    // which is `console/**` and `environment/**`: most of the console, including the one
    // page where an administrator sets somebody ELSE's password.
    //
    // The last fix here was `array_merge` instead of `+`, and its comment says the test
    // existed to catch exactly the file it could not see. Same defect, one layer down.
    $components = [];

    foreach ([resource_path('views/livewire'), ...array_filter((array) glob(base_path('modules/*/resources/views/livewire')), 'is_dir')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $components[] = $file->getPathname();
            }
        }
    }

    // A FLOOR: a walk that finds nothing reports a clean sweep.
    expect(count($components))->toBeGreaterThan(80, 'the component walk stopped finding the console');

    /** @var list<string> $unscreened */
    $unscreened = [];

    foreach ($components as $file) {
        $source = file_get_contents($file) ?: '';

        // A component "accepts a password" when it validates one into a real credential.
        // Detect by what the component DOES, not by one key spelling: account.blade.php
        // validates 'newPassword', so a 'password' => [...] pattern missed it entirely.
        // ANY key whose name says password, not two spellings. `environment/users/show`
        // validates `pwPassword` — the page where an administrator sets somebody ELSE's
        // credential — and was invisible to a detector listing `password|newPassword`,
        // even once the walk could reach the file.
        $setsPassword = preg_match("/'[A-Za-z_]*[Pp]assword[A-Za-z_]*'\s*=>\s*\[/", $source) === 1
            || str_contains($source, '->setPassword(')
            || str_contains($source, '->resetPassword(');

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
        //
        // There are now TWO ways to screen, and which one is right depends on the plane.
        // A tenant-plane form applies the environment's AuthPolicy, whose
        // requireBreachCheck runs the same corpus lookup — a fixed `new NotBreached`
        // beside a policy that already screens is a second, weaker opinion. The operator
        // plane sits above every environment, so no AuthPolicy governs it and the rule
        // is applied directly.
        $screened = str_contains($source, 'new NotBreached')
            || str_contains($source, 'PasswordMeetsPolicy::for(')
            // The reset form cannot resolve its subject without becoming an
            // account-existence oracle, so it screens through the guard and turns the
            // refusal into a field error.
            || str_contains($source, 'catch (PolicyViolation');

        if (! $screened) {
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

/**
 * The scan above proves the marker is present; this proves the mechanism works. The
 * policy's own breach screen is what the tenant-plane forms now rely on, so it has to
 * refuse at the primitive, not merely at the form that remembered to ask.
 */
it('refuses a breached password at the credential primitive itself', function (): void {
    app()->instance(BreachedPasswordCheck::class, new class implements BreachedPasswordCheck
    {
        public function isBreached(string $password): bool
        {
            return $password === 'the-breached-passphrase';
        }
    });

    expect(fn () => app(Subjects::class)->create('screened@corp.test', 'Screened', 'the-breached-passphrase'))
        ->toThrow(PolicyViolation::class);

    // A password the corpus does not know goes through untouched.
    expect(app(Subjects::class)->create('clean@corp.test', 'Clean', 'an-unbreached-passphrase')->email)
        ->toBe('clean@corp.test');
});
