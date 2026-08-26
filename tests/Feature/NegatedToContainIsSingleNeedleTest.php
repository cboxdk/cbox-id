<?php

declare(strict_types=1);

/**
 * `not->toContain()` MAY NAME ONLY ONE NEEDLE.
 *
 * Pest's `toContain` is VARIADIC and takes no message argument. The positive form is
 * therefore fine with several — `toContain('a', 'b')` means "contains both". The NEGATED
 * form means "contains none of them", so it passes the moment any one is absent — and a
 * second argument added as an explanatory message is a needle that never appears in the
 * haystack. The assertion is then unconditionally green.
 *
 * This has now happened three times in this repository. Twice it was found by somebody
 * reading the line; the third was found by deleting the code under test and watching the
 * suite stay green. A test that cannot fail is worse than no test, because it is counted.
 *
 * So it is swept for. The rule is narrow on purpose: a multi-needle POSITIVE `toContain` is
 * a legitimate and common assertion, and this says nothing about it.
 */
it('never negates toContain with more than one needle', function (): void {
    $offenders = [];
    $scanned = 0;

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('tests')));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, 'Test.php')) {
            continue;
        }

        $scanned++;
        $source = (string) file_get_contents((string) $file);

        // Comments first: this rule is documented in prose in at least one test, and a
        // sweep that reported its own explanation would be its own first offender.
        $source = (string) preg_replace('/\/\*.*?\*\//s', '', $source);
        $source = (string) preg_replace('/^\s*\/\/.*$/m', '', $source);

        /*
         * Matched to the closing parenthesis of the call, with no nested parens allowed
         * inside — a needle that is itself a call (`not->toContain(route('x'), 'y')`) is
         * rare enough to be worth missing, and allowing them would make this match past the
         * end of the statement and report every file.
         */
        if (preg_match_all('/not->toContain\(([^()]*)\)/s', $source, $matches) === false) {
            continue;
        }

        foreach ($matches[1] as $arguments) {
            // A trailing comma is a formatter's, not an argument.
            $arguments = rtrim(trim($arguments), ',');

            if ($arguments === '') {
                continue;
            }

            /*
             * COMMAS OUTSIDE THE QUOTES ONLY. `not->toContain('4,200')` is one needle that
             * happens to be a formatted number, and a naive `str_contains($arguments, ',')`
             * reported it — which is the shape of false positive that gets a guard deleted
             * rather than obeyed.
             */
            $stripped = (string) preg_replace(['/\'[^\']*\'/', '/"[^"]*"/'], ['', ''], $arguments);

            if (str_contains($stripped, ',')) {
                $offenders[] = str_replace(base_path().'/', '', (string) $file).': not->toContain('.trim($arguments).')';
            }
        }
    }

    // A FLOOR, so a moved test directory cannot empty the sweep and report success.
    expect($scanned)->toBeGreaterThan(100, 'the sweep found almost no test files; did the directory move?');

    expect($offenders)->toBe(
        [],
        'a negated toContain with more than one needle passes whenever ANY of them is absent — '
        ."split it into one assertion per needle, and drop any message argument:\n".implode("\n", $offenders),
    );
});
