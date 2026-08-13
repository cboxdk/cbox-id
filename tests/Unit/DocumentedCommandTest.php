<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * A documented command that does not exist is a support ticket with a head start.
 *
 * The configuration reference cited `cbox-id:access-control:sync-manifests`; the real
 * command is `cbox-id:app-manifests:sync`. An operator following the page verbatim gets
 * "command is not defined" and no clue which half of the name is wrong.
 *
 * Reads the command names out of the source rather than booting the console kernel, so
 * it stays a unit test and cannot be defeated by a command that is registered
 * conditionally.
 */
it('names only commands that exist', function (): void {
    $defined = [];

    foreach ([__DIR__.'/../../app', __DIR__.'/../../modules', __DIR__.'/../../vendor/cboxdk/laravel-id/src'] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (Finder::create()->files()->in($root)->name('*.php') as $file) {
            preg_match_all(
                '/\$signature\s*=\s*[\'"]([a-z0-9:_-]+)/i',
                (string) file_get_contents((string) $file->getRealPath()),
                $matches,
            );

            foreach ($matches[1] as $signature) {
                $defined[$signature] = true;
            }
        }
    }

    // Nothing to compare against means the extraction broke, not that the docs are
    // clean — fail rather than pass silently.
    expect(count($defined))->toBeGreaterThan(5);

    /**
     * Names that are cited but are NOT commands, with the reason. The retry sweep is a
     * scheduled closure that `schedule:list` displays under a command-shaped name — the
     * docs now say so explicitly, and the sentence saying so is itself a citation.
     *
     * @var array<string, string>
     */
    $notCommands = [
        'cbox-id:webhooks:retry' => 'a scheduled closure; the docs explain that it is not an artisan command',
    ];

    $missing = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../docs')->name('*.md') as $file) {
        preg_match_all('/`(cbox-id:[a-z0-9:_-]+)`/', (string) file_get_contents((string) $file->getRealPath()), $matches);

        foreach ($matches[1] as $cited) {
            if (! isset($defined[$cited]) && ! isset($notCommands[$cited])) {
                $missing[] = $cited.' in '.$file->getRelativePathname();
            }
        }
    }

    expect(array_unique($missing))->toBe([], 'documented but not defined: '.implode('; ', array_unique($missing)));
});

/**
 * AND THE OPTIONS, which is where it drifted next.
 *
 * `installation.md` documented `--account=` for `cbox-id:install`. The flag is
 * `--organization=` — renamed when a customer stopped being an "account" — so the one
 * command a stranger runs non-interactively, in CI, on a platform that refuses to install
 * twice, exited with "The --account option does not exist." before doing anything.
 *
 * The names test above could not see it: the command it cited was real. Options are the
 * other half of a command's contract, and they move for exactly the same reasons.
 */
it('documents only options the install command accepts', function (): void {
    $source = (string) file_get_contents(__DIR__.'/../../app/Console/Commands/InstallCommand.php');

    preg_match_all('/\{--([a-z0-9-]+)[=}\s]/i', $source, $defined);

    $accepted = array_flip($defined[1]);

    expect(count($accepted))->toBeGreaterThan(3, 'the signature read broke');

    $doc = (string) file_get_contents(__DIR__.'/../../docs/getting-started/installation.md');

    // The option table cites them as `--name=`; that is the shape to check.
    preg_match_all('/`--([a-z0-9-]+)=/i', $doc, $cited);

    $unknown = array_values(array_diff(array_unique($cited[1]), array_keys($accepted)));

    expect($unknown)->toBe([], 'installation.md documents options the command rejects: '.implode(', ', $unknown));
});
