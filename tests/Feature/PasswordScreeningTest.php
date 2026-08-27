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

    /*
     * WHEREVER THE FLOW LIVES, which is now three places.
     *
     * This walked Volt views only. Most password-accepting flows are controllers and form
     * requests since the Inertia port — signup, the reset, the forced change, invitation
     * acceptance — so a blade-only walk was auditing a shrinking remainder and calling it
     * the whole console. The floor below is what caught that, which is why it is here.
     *
     * The Volt roots are gone with the directories: there is no remainder left.
     */
    $roots = [
        base_path('app/Http/Controllers'),
        base_path('app/Http/Requests'),
        ...array_filter((array) glob(base_path('modules/*/src/Http/Controllers')), 'is_dir'),
        ...array_filter((array) glob(base_path('modules/*/src/Http/Requests')), 'is_dir'),
    ];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
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

        /*
         * A CONTROLLER'S RULES LIVE IN ITS FORM REQUEST, and that is the right place for
         * them — so reading one file at a time reported every ported flow as unscreened
         * while the rule sat one type-hint away. The request's source is read in alongside
         * the controller's, so the pair is judged as the one thing it is.
         */
        $source .= requestRulesReachableFrom($source);

        /*
         * AND THE PAIRING IS ASYMMETRIC, deliberately.
         *
         * A form request is also judged with the controller that consumes it, because the
         * screening can legitimately live there — a `catch (PolicyViolation)` around
         * `Subjects::create()` screens, and no rule in the request says so.
         *
         * But WHAT A FILE DOES is read from the file itself. A controller has several form
         * requests, and folding its whole source into each of them made every one of them
         * "set a password" the moment any sibling did: `SaveProfileRequest` — a form whose
         * only field is a display name — was reported as an unscreened credential flow
         * because the controller it shares also changes passwords. Evidence of screening
         * may come from the neighbourhood; evidence of the hazard may not.
         */
        $context = $source.consumersOf($file);

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

        /*
         * VERIFYING A PASSWORD IS NOT SETTING ONE, and screening on the way IN would be
         * actively wrong: it would block a legitimate sign-in for somebody whose existing
         * password has since appeared in a corpus, and turn the form into an inventory of
         * which accounts have weak ones.
         *
         * Detected by the VERB, not by one class name. This carve-out used to look for
         * `AttemptOutcome`, which the Volt login blade happened to mention — the ported
         * `LoginRequest` and `ConfirmSudoRequest` declare a bare `'password' => [...]` and
         * hand it to a verifier, and both were reported as unscreened credential-setting
         * flows they have never been.
         */
        $verifiesOnly = str_contains($context, 'AttemptOutcome')
            || str_contains($context, '->attempt(')
            || str_contains($context, '->attemptPassword(')
            || str_contains($context, '->verifyPassword(');

        if ($verifiesOnly && ! str_contains($source, '->setPassword(') && ! str_contains($source, '->resetPassword(')) {
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
        $screened = str_contains($context, 'new NotBreached')
            || str_contains($context, 'PasswordMeetsPolicy::for(')
            /*
             * Screened at the credential primitive itself, with the refusal turned into an
             * answer. {@see Subjects::create()} applies the environment's whole policy —
             * length, reuse, the breach corpus — so a caller that lets it and reports what
             * it said has screened; one that lets it and drops the exception has a 500
             * where a refusal belongs, which is a different bug and not this one's.
             */
            || str_contains($context, 'catch (PolicyViolation');

        if (! $screened) {
            $unscreened[] = str_replace(base_path().'/', '', $file);
        }
    }

    expect($unscreened)->toBe(
        [],
        'These flows set a password without screening it against the breach corpus: '
        .implode(', ', $unscreened)
    );
});

/**
 * The rules a file DELEGATES to, as source.
 *
 * A controller type-hints `SaveSomethingRequest $request` and the rules live there. Only
 * the app's own request classes are followed — a type-hint on a framework class is not a
 * place rules can be hiding.
 */
function requestRulesReachableFrom(string $source): string
{
    preg_match_all('/\b([A-Z][A-Za-z0-9]*Request)\s+\$/', $source, $matches);

    $extra = '';

    foreach (array_unique($matches[1]) as $class) {
        foreach ((array) glob(base_path('app/Http/Requests/**/'.$class.'.php')) as $path) {
            if (is_string($path) && is_file($path)) {
                $extra .= (string) file_get_contents($path);
            }
        }
    }

    return $extra;
}

/**
 * The code that CONSUMES a form request, as source.
 *
 * The link has to run both ways. A controller's rules live in its request, and a request's
 * PURPOSE lives in its controller — `LoginRequest` declares a bare `'password' => [...]`
 * and hands it to a verifier, which is the fact that makes it a sign-in rather than a
 * credential-setting flow. Read alone it is indistinguishable from the latter, and this
 * sweep reported both of the platform's verify-only requests as unscreened.
 */
function consumersOf(string $path): string
{
    if (! str_contains($path, '/app/Http/Requests/')) {
        return '';
    }

    $class = basename($path, '.php');
    $extra = '';

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('app/Http/Controllers'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, $class.' $')) {
            $extra .= $source;
        }
    }

    return $extra;
}

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
