<?php

declare(strict_types=1);

use App\Console\Support\SecretLine;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A credential printed to a terminal must be the credential.
 *
 * The install command showed a generated operator password through `$this->line()`,
 * which writes via Symfony's OutputFormatter — and the formatter reads `<...>` as style
 * markup and consumes it. `Str::password(28)` draws from an alphabet containing `<` and
 * `>`, so a password could be printed with characters missing while the REAL one was
 * hashed and stored. Shown once, plausible-looking, and useless: the operator is locked
 * out of the deployment they have just installed.
 *
 * It surfaced as a CI failure on one engine leg and looked like a flake, because it only
 * bites when the random password happens to contain markup — measured at 142 in 20,000.
 * That rate is exactly why this is asserted against hostile strings here instead of being
 * left to the install test's dice.
 */
it('prints a secret containing console markup byte for byte', function (string $secret): void {
    $output = new BufferedOutput;

    SecretLine::write($output, $secret);

    expect(rtrim($output->fetch(), "\n"))->toBe($secret);
})->with([
    // The shape that actually bit, from the failing CI run's own output.
    'a real generated password' => 'QA14pB],i#\<&mK{izq]J-1XrjRp',
    // Unambiguous markup: the formatter would render this as styled text and drop the tag.
    'a complete tag' => 'abc<info>def</info>ghi',
    // A lone opener, which is what most often loses a single character.
    'a bare opener' => 'pass<word123',
    'a bare closer' => 'pass>word123',
    // The escape the formatter itself uses; it must not be interpreted either.
    'a backslash escape' => 'pa\\<ss\\>word',
]);

/**
 * The property that matters, over the real generator rather than hand-picked strings.
 *
 * A thousand draws puts the chance of seeing at least one markup-bearing password above
 * 99.9% at the measured rate, so this fails reliably if the raw write is ever reverted —
 * and it exercises the alphabet as it actually is rather than as this test imagines it.
 */
it('prints every generated password byte for byte', function (): void {
    $mangled = [];

    for ($i = 0; $i < 1000; $i++) {
        $password = Str::password(28);
        $output = new BufferedOutput;

        SecretLine::write($output, $password);

        if (rtrim($output->fetch(), "\n") !== $password) {
            $mangled[] = $password;
        }
    }

    expect($mangled)->toBe([], count($mangled).' generated passwords printed differently from the credential stored');
});
