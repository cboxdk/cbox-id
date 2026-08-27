<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * A TEST FILE MUST NOT IMPORT A GLOBAL CLASS.
 *
 * `use RecursiveDirectoryIterator;` at the top of a Pest test is a no-op — the file is in
 * the global namespace, so the class was already reachable — and PHP 8.5 raises a warning
 * saying so. `phpunit.xml` sets `failOnWarning="true"`, so that warning becomes a FAILING
 * RUN, and this is what a failing run looks like:
 *
 *     Tests:  1904 passed (10177 assertions)
 *     ##[error]Process completed with exit code 1
 *
 * A green summary and a red exit code, on all four CI legs, with nothing named. The
 * warning is raised while the suite's files are being LOADED — before any test starts — so
 * it belongs to no test, appears in no report, is absent from `--log-junit` and is not
 * printed by `--display-all-issues`. Reaching it took instrumenting Pest's kernel to
 * enumerate the result object's warning events by hand.
 *
 * Two lines in one file cost that. The guard costs a directory scan.
 *
 * NOTE THE ASYMMETRY: inside `app/` these imports are correct and necessary, because those
 * files declare a namespace and the class genuinely has to be brought into it. It is only
 * in the global namespace that the statement means nothing — which is why this sweeps the
 * test directory and nothing else.
 */
it('imports no global class into a test file', function (): void {
    $offenders = [];
    $scanned = 0;

    foreach (File::allFiles(base_path('tests')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) $file->getContents();

        // A test file declares no namespace; the helpers under tests/Support do, and their
        // imports are as meaningful as any other namespaced file's.
        if (preg_match('/^namespace\s+/m', $source) === 1) {
            continue;
        }

        $scanned++;

        /*
         * A bare `use Foo;` — no backslash anywhere in the name. `use App\Foo;` and
         * `use Foo\Bar;` are both compound and both fine; it is the single-segment form
         * that PHP warns has no effect.
         */
        if (preg_match_all('/^use\s+([A-Za-z_][A-Za-z0-9_]*)\s*;/m', $source, $found) === false) {
            continue;
        }

        foreach ($found[1] as $imported) {
            $offenders[] = $file->getRelativePathname().': use '.$imported.';';
        }
    }

    // Guard the guard: the sweep has to have looked at the suite, not at an empty list.
    expect($scanned)->toBeGreaterThan(100, 'almost no test files were scanned — did the directory move?');

    expect($offenders)->toBe([], implode("\n", [
        'These imports are no-ops that PHP 8.5 warns about, and `failOnWarning` turns each',
        'warning into a failing run with a green summary. Delete the line — the class is',
        'already reachable from the global namespace:',
        ...$offenders,
    ]));
});
