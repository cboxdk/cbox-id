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
