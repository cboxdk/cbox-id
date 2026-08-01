<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * A helper used by more than one test FILE must live in tests/Pest.php.
 *
 * Under `pest --parallel`, paratest gives each worker a subset of the files, so a
 * function declared in tests/Feature/A.php does not exist in the process running
 * tests/Feature/B.php — B dies with "Call to undefined function". Which files share a
 * worker depends on the shard split, so this reads as an intermittent, unrelated
 * failure rather than a missing declaration: 48 tests failed this way while the same
 * suite was green serially, long enough for it to be mistaken for background noise.
 *
 * Pest.php is loaded by every worker, so declarations there are always available.
 *
 * Tokenised rather than grepped, on purpose. A regex over the source flags the `owner`
 * in `it('refuses to impersonate an owner (403)')` and the `deliver` in an anonymous
 * class's `public function deliver(...)` — a guard that cries wolf gets deleted.
 */

/**
 * Top-level function and constant declarations in one file.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<string>
 */
function topLevelDeclarations(array $tokens): array
{
    $names = [];
    $depth = 0;

    foreach ($tokens as $i => $token) {
        // T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES are the `{` of `"{$x}"` and `"${x}"`.
        // They tokenise as arrays while their closing brace is a plain '}' string, so a
        // depth counter that only watches for '{' drifts negative on the first
        // interpolated string and then never sees column zero again.
        if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;

            continue;
        }

        if ($token === '}') {
            $depth--;

            continue;
        }

        if ($depth !== 0 || ! is_array($token) || ! in_array($token[0], [T_FUNCTION, T_CONST], true)) {
            continue;
        }

        // The declared name is the next T_STRING (skipping whitespace and `&`).
        for ($j = $i + 1; $j < count($tokens); $j++) {
            $next = $tokens[$j];

            if (is_array($next) && $next[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($next) && $next[0] === T_STRING) {
                $names[] = $next[1];
            }

            break;
        }
    }

    return $names;
}

/**
 * Every identifier this file references as a bare function call or constant —
 * excluding method declarations, method calls, static calls and `new`.
 *
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<string>
 */
function bareReferences(array $tokens): array
{
    $meaningful = array_values(array_filter(
        $tokens,
        static fn ($t): bool => ! is_array($t) || ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $names = [];
    $bound = [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CONST];

    foreach ($meaningful as $i => $token) {
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $previous = $meaningful[$i - 1] ?? null;

        if (is_array($previous) && in_array($previous[0], $bound, true)) {
            continue;
        }

        // `\name(...)` is namespaced, not one of ours.
        if (is_array($previous) && $previous[0] === T_NS_SEPARATOR) {
            continue;
        }

        $names[] = $token[1];
    }

    return $names;
}

it('declares every cross-file test helper in Pest.php', function (): void {
    /** @var array<string, list<array{0: int, 1: string, 2: int}|string>> $tokensByFile */
    $tokensByFile = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__))->name('*.php') as $file) {
        $path = $file->getRealPath();

        if ($path === false || basename($path) === 'Pest.php') {
            continue;
        }

        $tokensByFile[$path] = token_get_all((string) file_get_contents($path));
    }

    /** @var array<string, string> $declaredIn  helper name => the one file declaring it */
    $declaredIn = [];

    foreach ($tokensByFile as $path => $tokens) {
        foreach (topLevelDeclarations($tokens) as $name) {
            $declaredIn[$name] = $path;
        }
    }

    /** @var array<string, list<string>> $referencedBy  helper name => files referencing it */
    $referencedBy = [];

    foreach ($tokensByFile as $path => $tokens) {
        foreach (array_unique(bareReferences($tokens)) as $name) {
            if (isset($declaredIn[$name]) && $declaredIn[$name] !== $path) {
                $referencedBy[$name][] = basename($path);
            }
        }
    }

    $leaked = [];

    foreach ($referencedBy as $name => $files) {
        $leaked[$name] = basename($declaredIn[$name]).' → '.implode(', ', $files);
    }

    expect($leaked)->toBe([], "Declared in one test file, used from another — move to tests/Pest.php:\n  ".implode("\n  ", array_map(
        static fn (string $name, string $where): string => $name.'  ('.$where.')',
        array_keys($leaked),
        $leaked,
    )));
});
