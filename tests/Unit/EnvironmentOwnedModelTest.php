<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * A model that stores tenant data and forgets the tenancy trait is a silent cross-tenant
 * leak with a green suite. Two shipped that way — risk events and audit-export
 * bookkeeping — and neither was caught by a test; one was caught by a second-vendor
 * review and the other by reading the migration beside it.
 *
 * So: every model this repository defines must either carry `BelongsToEnvironment` or
 * say, in one line, why it does not. The exemption list is the point — it is short,
 * every entry states its reason, and adding to it is a decision someone has to write
 * down rather than an omission nobody notices.
 */
it('gives every app model a tenancy decision, not a default', function (): void {
    /**
     * Not environment-owned, with the reason. Anything absent from this list must carry
     * the trait.
     *
     * @var array<string, string>
     */
    $exempt = [
        // Carries its own environment_id from the EVENT, not from ambient context. The
        // trait would stamp whichever environment the relay happens to be running in,
        // which is not the one the event belongs to. Reasoned in the model's docblock.
        'AnalyticsEvent' => 'stamps the environment from the event, not the request',
    ];

    $missing = [];

    $roots = [__DIR__.'/../../app/Models', __DIR__.'/../../modules'];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (Finder::create()->files()->in($root)->path('Models')->name('*.php') as $file) {
            $source = (string) file_get_contents((string) $file->getRealPath());
            $class = $file->getBasename('.php');

            if (! str_contains($source, 'extends Model')) {
                continue;
            }

            // The trait must be USED in the class body, not merely imported. Checking
            // for the bare name matches the `use Cbox\Id\...\BelongsToEnvironment;`
            // import that survives when someone deletes the trait line — which is
            // exactly the half-removal this guard exists to catch.
            $usesTrait = preg_match('/^\s+use BelongsToEnvironment;/m', $source) === 1;

            if ($usesTrait || isset($exempt[$class])) {
                continue;
            }

            $missing[] = $class;
        }
    }

    expect($missing)->toBe([], implode(', ', $missing)
        .' store tenant data without BelongsToEnvironment. Add the trait, or add the class '
        .'to the exemption list in this test with the reason it is not environment-owned.');
});
