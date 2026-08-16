<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * A file named `*Test.php` claims that the thing it is named after is tested.
 *
 * The framework repo carried three zero-byte test files — created empty, never populated,
 * content gone to a differently-named sibling in the same commit — and nothing noticed,
 * because a file with no tests in it cannot fail. This repo carried the Laravel skeleton's
 * `ExampleTest::test_that_true_is_true()`, which is the same failure with a body: an
 * assertion that cannot distinguish a working application from a deleted one.
 *
 * Neither is a tidiness problem. Somebody asking "is X tested" finds a file named after X,
 * does not open it, and concludes yes. A file that answers the question wrongly is worse
 * than one that is absent.
 *
 * It also scans `modules/`, which the sibling sweeps in this suite have repeatedly
 * forgotten — a module is where a test is most likely to be written once and never run.
 */
it('has no test file that contains no real test', function (): void {
    /** @var list<string> $hollow */
    $hollow = [];
    $scanned = 0;

    $finder = Finder::create()
        ->files()
        ->in([base_path('tests'), base_path('modules')])
        ->name('*Test.php');

    foreach ($finder as $file) {
        $scanned++;
        $source = (string) file_get_contents((string) $file->getRealPath());

        // Pest's two spellings plus PHPUnit's, so this does not quietly start ignoring a
        // file written in a style this suite already contains.
        $declaresATest = preg_match('/\b(it|test)\s*\(/', $source) === 1
            || preg_match('/function\s+test[A-Z_]/', $source) === 1;

        if (! $declaresATest) {
            $hollow[] = $file->getRelativePathname();

            continue;
        }

        // A file whose ONLY assertion is `assertTrue(true)` — the skeleton's shape. It
        // declares a test, so the check above is satisfied, and it still proves nothing.
        if (preg_match('/assertTrue\(\s*true\s*\)/', $source) === 1
            && preg_match('/expect\(|assert(?!True\(\s*true\s*\))[A-Z]/', $source) !== 1) {
            $hollow[] = $file->getRelativePathname().' (asserts only that true is true)';
        }
    }

    // A floor: a sweep that found nothing to check is indistinguishable from a sweep that
    // found nothing wrong, and this one walks two directory trees.
    expect($scanned)->toBeGreaterThan(150, 'the suite sweep found almost no test files — it is looking in the wrong place');

    expect($hollow)->toBe([], 'test files that prove nothing: '.implode(', ', $hollow));
});
