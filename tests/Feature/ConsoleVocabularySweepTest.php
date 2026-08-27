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
/**
 * THE PARTS OF A REACT FILE A PERSON ACTUALLY READS.
 *
 * A blade file is almost entirely copy, so the sweep below could match its whole source.
 * A `.tsx` is mostly identifiers — and `export default function Accounts()` is a component
 * name, not a heading. Matched raw, the rule reported the sign-in page's own component as
 * user-facing copy calling a customer an account, which is the kind of false positive that
 * teaches people to add exemptions until the rule means nothing.
 *
 * So only two things are kept, and they are the two a reader sees: JSX TEXT (`>Accounts<`)
 * and STRING LITERALS (`label="Accounts"`). The angle brackets around text are preserved
 * because one of the rules below is about a heading whose entire content is the word.
 *
 * Import lines go first: a module path is not copy, and `@/pages/auth/accounts` would
 * otherwise be a string literal the sweep read.
 */
function readableCopyIn(string $source): string
{
    $source = (string) preg_replace('/^\s*import .*$/m', '', $source);

    $copy = [];

    // JSX text — no tags, no expressions between the brackets.
    if (preg_match_all('/>[^<>{}]*</s', $source, $text) === false) {
        return '';
    }

    $copy = array_merge($copy, $text[0]);

    // String literals, single- and double-quoted. Template literals are matched separately
    // because a backtick string may legitimately contain a quote of the other kinds.
    foreach (["/'[^'\\n]*'/", '/"[^"\\n]*"/', '/`[^`]*`/s'] as $pattern) {
        if (preg_match_all($pattern, $source, $literals) !== false) {
            $copy = array_merge($copy, $literals[0]);
        }
    }

    return implode("\n", $copy);
}

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

    // FIRST PERSON IS NOT THE BANNED MEANING. The rule above is about the OPERATOR's
    // third-person listing — a table of other people's companies headed "Accounts" while
    // the URL says `customers`. In a customer's OWN console, "this account" names the
    // thing they are signed in to and administering, which is the one place the word is
    // the clearest available: they are not "a customer" to themselves.
    //
    // This exemption exists because the word "organization" does two jobs — a customer in
    // the platform root, one of that customer's end-user teams inside an environment —
    // and both appear in this one console. Reading "Organizations" on the environment
    // rail and "Organization settings" on their own is what makes people conclude the two
    // are the same thing one level apart. See docs/core-concepts/accounts-and-organizations.md.
    //
    // Narrow on purpose: one phrase, one directory. The plural listing stays banned
    // everywhere, including here.
    //
    // `account owner` joins it for the same reason: the transfer-ownership dialog on the
    // customer's OWN console hands over the account, and calling that person the
    // "organization owner" is the collision this rename exists to remove. In the
    // operator's listing of other people's companies it stays banned.
    $firstPersonExempt = ['/\bthis account\b/', '/\baccount owner\b/'];

    // BOTH SPELLINGS OF THE SAME DIRECTORY. The customer's own console pages are blade
    // until they port and React afterwards, and the exemption is about which PAGES these
    // are, not which language they happen to be written in.
    $firstPersonRoots = [
        resource_path('views/livewire/console'),
        base_path('resources/js/pages/console'),
    ];

    /*
     * AND THE PORTED COPY, which is where most of it now lives.
     *
     * This swept `.blade.php` only. Every page that ports takes its sentences out of the
     * sweep with it, so a rule about the words the product uses would have quietly stopped
     * applying to the product — and the floor below, which exists to catch exactly that,
     * was the thing that caught it.
     */
    $roots = [resource_path('views'), base_path('resources/js')];

    /*
     * A MODULE'S COPY IS IN ITS `resources/js` NOW, and this looked only at
     * `resources/views`. Every module page ported out of the sweep the moment it became a
     * `.tsx`, and the docblock above says in as many words that modules are where the
     * drift is likeliest — so the rule had stopped applying exactly where it claimed to
     * matter most. Both directories, so a module that keeps a blade (a mail template, an
     * error page) is still read.
     */
    foreach (['views', 'js'] as $kind) {
        foreach ((array) glob(base_path('modules/*/resources/'.$kind)) as $moduleRoot) {
            if (is_string($moduleRoot) && is_dir($moduleRoot)) {
                $roots[] = $moduleRoot;
            }
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

        if (! str_ends_with($path, '.blade.php') && ! str_ends_with($path, '.tsx') && ! str_ends_with($path, '.ts')) {
            continue;
        }

        /*
         * GENERATED ROUTE HELPERS ARE NOT COPY. Wayfinder writes one `.ts` per route under
         * `actions/` and `routes/` — 366 files of URLs and method names, which would swamp
         * the floor below with files nobody reads and match `/platform/customers` as
         * though it were a sentence.
         */
        if (str_contains($path, '/resources/js/actions/') || str_contains($path, '/resources/js/routes/')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        // Strip every kind of comment: blade, JSX, PHP/JS block, line.
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = (string) preg_replace('/\{\/\*.*?\*\/\}/s', '', $source);
        $source = (string) preg_replace('/\/\*.*?\*\//s', '', $source);
        $source = (string) preg_replace('/^\s*\/\/.*$/m', '', $source);

        if (str_ends_with($path, '.tsx') || str_ends_with($path, '.ts')) {
            $source = readableCopyIn($source);
        }

        $scanned++;

        $inOwnConsole = false;

        foreach ($firstPersonRoots as $root) {
            $inOwnConsole = $inOwnConsole || str_starts_with($path, $root);
        }

        foreach ($forbidden as $pattern) {
            if ($inOwnConsole && in_array($pattern, $firstPersonExempt, true)) {
                continue;
            }

            if (preg_match($pattern, $source, $m) === 1) {
                $offenders[] = str_replace([resource_path('views/'), base_path('')], '', $path).': "'.$m[0].'"';
            }
        }
    }

    /*
     * A FLOOR, so a moved directory cannot empty the sweep and report success.
     *
     * It counted both stacks while both existed, so porting a page moved it from one side
     * to the other and left the number alone — which is what made the floor a statement
     * about coverage rather than a number somebody edits downwards every few weeks. The
     * blade stack is gone now, and the number held: the module React pages and the
     * hand-written `.ts` this was widened to reach are copy that was never being swept at
     * all. It may go UP. It may not come down without a reason written here.
     */
    expect($scanned)->toBeGreaterThan(180, 'the copy sweep found almost no views; did the directory move?');

    expect($offenders)->toBe(
        [],
        "user-facing copy still calls a customer an account:\n".implode("\n", $offenders),
    );
});
