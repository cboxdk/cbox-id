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

        // Pre-auth security telemetry with no capability in a row, and its only consumer
        // is a deployment-wide tuning query run from a console session with no
        // environment in context — where the hard outer scope would emit `1 = 0` and
        // report an empty corpus, which is the exact failure the table exists to fix. The
        // environment is an ordinary column so a query can still narrow to one. Reasoned
        // at length in the model's docblock; surfaced here the moment this guard stopped
        // skipping app/Models.
        'RiskDecision' => 'pre-auth telemetry read deployment-wide, with no environment in context',
    ];

    $missing = [];

    /**
     * Two finders, not one root list with a path filter.
     *
     * `->path('Models')` matches against the path RELATIVE to the root, so rooted at
     * `app/Models` the candidates are `RiskDecision.php` — never containing "Models" —
     * and every app model was silently skipped. The modules root worked, because its
     * relative paths look like `devices/src/Models/Device.php`. So this guard, written
     * because RiskEvent and AuditExportCursor both shipped without a tenancy decision,
     * was watching half the codebase. Proven by dropping a trait-less model into
     * app/Models: the test passed in 0.01s.
     */
    $finders = array_filter([
        is_dir(__DIR__.'/../../app/Models') ? Finder::create()->files()->in(__DIR__.'/../../app/Models')->name('*.php') : null,
        is_dir(__DIR__.'/../../modules') ? Finder::create()->files()->in(__DIR__.'/../../modules')->path('/Models/')->name('*.php') : null,
    ]);

    $checked = 0;

    foreach ($finders as $finder) {
        foreach ($finder as $file) {
            $source = (string) file_get_contents((string) $file->getRealPath());
            $class = $file->getBasename('.php');

            if (! str_contains($source, 'extends Model')) {
                continue;
            }

            $checked++;

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

    // Finding nothing to check means the sweep broke, not that the codebase is clean —
    // which is precisely how this guard spent its life watching zero app models.
    expect($checked)->toBeGreaterThan(10, "the model sweep examined {$checked} files");

    expect($missing)->toBe([], implode(', ', $missing)
        .' store tenant data without BelongsToEnvironment. Add the trait, or add the class '
        .'to the exemption list in this test with the reason it is not environment-owned.');
});
