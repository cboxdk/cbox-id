#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * License gate. Fails (exit 1) if any installed dependency is not offered under
 * at least one allowed license. Composer's `license` array is disjunctive, and
 * so is SPDX "OR": dual-licensed packages (e.g. nette's "BSD-3-Clause OR
 * GPL-3.0-only") pass as long as ONE choice is allowed — which is exactly the
 * choice we exercise. SPDX "AND" is the opposite kind of claim: "MIT AND
 * GPL-2.0-only" binds the work to both at once, so every conjunct must be
 * allowed. Expressions are read as OR-of-ANDs with parentheses stripped, not
 * parsed — composer.lock does not nest them in practice, and a full SPDX parser
 * is not a dependency a license gate should carry. Run: `composer license-check`.
 */
const ALLOWED = [
    'MIT', 'MIT-0', 'ISC', '0BSD', 'Unlicense', 'WTFPL', 'CC0-1.0',
    'BSD-2-Clause', 'BSD-3-Clause', 'BSD-3-Clause-Clear', 'BSD-4-Clause',
    'Apache-2.0', 'Apache2', 'BSL-1.0', 'Zlib', 'PHP-3.01',
];

/** Packages permitted despite a missing/odd license field, with justification. */
const EXCEPTIONS = [
    // e.g. 'vendor/pkg' => 'public domain, confirmed upstream',
];

$lockPath = dirname(__DIR__).'/composer.lock';

if (! is_file($lockPath)) {
    fwrite(STDERR, "composer.lock not found; run `composer install` first.\n");
    exit(2);
}

$lock = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);

$includeDev = in_array('--dev', $argv, true);
$packages = $lock['packages'] ?? [];

if ($includeDev) {
    $packages = array_merge($packages, $lock['packages-dev'] ?? []);
}

$violations = [];
$checked = 0;

foreach ($packages as $package) {
    $name = (string) ($package['name'] ?? '?');
    $checked++;

    if (isset(EXCEPTIONS[$name])) {
        continue;
    }

    $expressions = licenseExpressions($package['license'] ?? []);

    if ($expressions === []) {
        $violations[$name] = '(no license declared)';

        continue;
    }

    $allowed = array_filter($expressions, 'expressionAllowed');

    if ($allowed === []) {
        $violations[$name] = implode(' OR ', $expressions);
    }
}

/**
 * The entries of a composer license field, one SPDX expression each. The array
 * form is disjunctive by definition, so each entry stands on its own.
 *
 * @param  list<string>|string  $license
 * @return list<string>
 */
function licenseExpressions(array|string $license): array
{
    $items = is_array($license) ? $license : [$license];
    $out = [];

    foreach ($items as $item) {
        $item = trim((string) $item);
        if ($item !== '') {
            $out[] = $item;
        }
    }

    return array_values(array_unique($out));
}

/**
 * Whether one SPDX expression is satisfiable under ALLOWED. "OR" offers a
 * choice, so a single allowed alternative is enough; "AND" imposes every
 * conjunct, so an alternative passes only when all of its parts are allowed.
 * "MIT OR GPL-2.0-only" passes here; "MIT AND GPL-2.0-only" does not.
 */
function expressionAllowed(string $expression): bool
{
    // Stripped, not parsed: guessing at precedence beyond OR-of-ANDs would be
    // pretending to a parser this script deliberately is not.
    $expression = str_replace(['(', ')'], ' ', $expression);

    foreach (preg_split('/\s+OR\s+/i', trim($expression)) ?: [] as $alternative) {
        $conjuncts = array_filter(array_map('trim', preg_split('/\s+AND\s+/i', $alternative) ?: []), static fn (string $c): bool => $c !== '');

        $disallowed = array_filter($conjuncts, static fn (string $c): bool => ! in_array($c, ALLOWED, true));

        if ($conjuncts !== [] && $disallowed === []) {
            return true;
        }
    }

    return false;
}

$scope = $includeDev ? 'production + dev' : 'production';

if ($violations !== []) {
    fwrite(STDERR, "License check FAILED ({$scope}): disallowed or missing licenses\n\n");
    foreach ($violations as $name => $license) {
        fwrite(STDERR, sprintf("  %-45s %s\n", $name, $license));
    }
    fwrite(STDERR, "\nAllowed: ".implode(', ', ALLOWED)."\n");
    fwrite(STDERR, "If a flagged package is genuinely fine, add it to EXCEPTIONS with a reason.\n");
    exit(1);
}

echo "License check passed: all {$checked} {$scope} dependencies are permissively licensed.\n";
exit(0);
