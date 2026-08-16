<?php

declare(strict_types=1);

/**
 * The console does not call a CUSTOMER an "account" in anything a person reads.
 *
 * The word is still right for a person's OWN account — "My account", "Switch account",
 * connected accounts, service accounts — so this cannot be a blanket ban, and a blanket
 * rename is exactly what would break those. What it forbids is the account plane's meaning:
 * the customer that owns projects, environments and billing.
 *
 * This exists because renaming the routes, the classes and the files left every label,
 * heading and column on the operator console still saying "Accounts". The URL said
 * `/platform/customers` and the page said "Accounts" — which is worse than either alone,
 * because now the product disagrees with itself in front of the operator.
 *
 * Comments are stripped before matching: the history of WHY the plane went is worth keeping
 * in the source, and a reader of the code is not a reader of the UI.
 *
 * IT SCANS `modules/` TOO. It walked `resources/views` alone, so a module page could say
 * "Accounts" in a heading indefinitely — and modules are where the drift is likeliest,
 * because a module ships its own copy and nobody renaming the plane goes looking in it.
 */
it('never calls a customer an account in user-facing copy', function (): void {
    /** @var list<string> $offenders */
    $offenders = [];
    $scanned = 0;

    // The account-plane meaning, as REGEXES, because the near misses matter more than the
    // hits. "Service accounts" are OAuth service accounts and "If an account exists for
    // <email>" is the user's own login — both correct, and a rule that flags them is a rule
    // people learn to ignore. So `Accounts` is anchored to a word boundary and excluded
    // after "Service ".
    $forbidden = [
        '/(?<!Service )\bAccounts\b/',
        '/\bAccount ID\b/',
        '/\bthis account\b/',
        '/\baccount owner\b/',
        '/\bNo accounts\b/',
        '/\baccounts list\b/',
        '/\baccount console\b/',
        '/\baccount plane\b/',
        '/\baccount members\b/i',
        // A column header or label whose entire content is the word — `<th>Account</th>`,
        // `<dt>Account</dt>`. Caught separately from the plural because the singular is
        // legal in a sentence ("My account") and illegal as a heading for a customer.
        '/>\s*Account\s*</',
    ];

    $roots = [resource_path('views')];

    foreach ((array) glob(base_path('modules/*/resources/views')) as $moduleViews) {
        if (is_string($moduleViews) && is_dir($moduleViews)) {
            $roots[] = $moduleViews;
        }
    }

    // The module roots have to be FOUND, not assumed: a renamed layout would silently
    // narrow this back to the app's own views, which is the state it is being widened out
    // of. Modules exist in this repo, so finding none means the glob is wrong.
    expect(count($roots))->toBeGreaterThan(3, 'no module view directories were found — the sweep narrowed itself');

    /** @var list<string> $paths */
    $paths = [];

    foreach ($roots as $root) {
        /** @var iterable<SplFileInfo> $found */
        $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($found as $file) {
            $paths[] = (string) $file;
        }
    }

    foreach ($paths as $path) {

        if (! str_ends_with($path, '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        // Strip every kind of comment: blade, PHP block, PHP line.
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = (string) preg_replace('/\/\*.*?\*\//s', '', $source);
        $source = (string) preg_replace('/^\s*\/\/.*$/m', '', $source);

        $scanned++;

        foreach ($forbidden as $pattern) {
            if (preg_match($pattern, $source, $m) === 1) {
                $offenders[] = str_replace([resource_path('views/'), base_path('')], '', $path).': "'.$m[0].'"';
            }
        }
    }

    // A FLOOR, so a moved view directory cannot empty the sweep and report success.
    expect($scanned)->toBeGreaterThan(80, 'the copy sweep found almost no views; did the directory move?');

    expect($offenders)->toBe(
        [],
        "user-facing copy still calls a customer an account:\n".implode("\n", $offenders),
    );
});
