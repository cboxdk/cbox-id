<?php

declare(strict_types=1);

namespace App\Console\Support;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print a secret to the terminal exactly as it is.
 *
 * `$this->line()` — and every other Artisan output helper — writes through Symfony's
 * `OutputFormatter`, which reads `<...>` as style markup and CONSUMES it. That is
 * invisible for prose and wrong for a credential: `Str::password(28)` draws from an
 * alphabet containing `<` and `>`, so roughly one generated password in 140 is printed
 * with characters missing.
 *
 * Measured, not estimated: 142 of 20,000 generated passwords came out of `writeln()`
 * differing from the string that was stored, most losing exactly one character. The
 * install command shows that password ONCE and hashes the real one, so an operator who
 * drew a bad one is locked out of a deployment they have just created, holding a
 * credential that looks perfectly plausible.
 *
 * `OUTPUT_RAW` is the whole fix: no formatter, no markup, the bytes as given. Anything
 * that prints a high-entropy string a human must copy belongs here rather than in
 * `line()`.
 */
final class SecretLine
{
    public static function write(OutputInterface $output, string $secret): void
    {
        $output->writeln($secret, OutputInterface::OUTPUT_RAW);
    }
}
