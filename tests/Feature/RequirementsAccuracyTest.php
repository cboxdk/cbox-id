<?php

declare(strict_types=1);

/**
 * The requirements page and `composer.json` must agree.
 *
 * This is the one document that has to be right: an operator pins what it says into a
 * downstream manifest. It said `>=0.58 <1.0` for the framework while the resolver
 * enforced `>=0.74 <1.0`, so anyone following it got an unresolvable install — and three
 * direct dependencies were absent from it entirely.
 *
 * The page drifts because it is prose about a machine-readable fact, which is exactly the
 * shape a test should hold together. Same trick the OpenAPI coverage gate uses for routes.
 */
it('states the version ranges composer actually enforces', function (): void {
    /** @var array<string, string> $required */
    $required = json_decode((string) file_get_contents(base_path('composer.json')), true)['require'] ?? [];
    $doc = (string) file_get_contents(base_path('docs/requirements.md'));

    $wrong = [];
    $absent = [];

    foreach ($required as $package => $constraint) {
        // php and ext-* are described in prose above the tables, not as rows.
        if ($package === 'php' || str_starts_with($package, 'ext-')) {
            continue;
        }

        if (! str_contains($doc, '`'.$package.'`')) {
            $absent[] = $package;

            continue;
        }

        if (! str_contains($doc, '`'.$constraint.'`')) {
            $wrong[] = $package.' is `'.$constraint.'` in composer.json';
        }
    }

    expect(count($required))->toBeGreaterThan(5, 'the manifest read broke');
    expect($absent)->toBe([], 'required but absent from requirements.md: '.implode(', ', $absent));
    expect($wrong)->toBe([], 'requirements.md states a different range: '.implode('; ', $wrong));
});

/**
 * …and the other direction, which is the one that actually bit.
 *
 * The check above only asks whether every required package is documented. It says nothing
 * about a documented package that is required by nothing — and the page listed
 * `laravel/socialite` and `socialiteproviders/microsoft` for a year, with versions and a
 * purpose, neither of them in `composer.json`, `composer.lock` or a single line of code.
 * Social sign-in is the framework's own `Federation` stack.
 *
 * An operator pinning this page into a downstream manifest gets two packages they do not
 * need; one auditing the deployment goes looking for a supply chain that is not there.
 *
 * TABLE ROWS only. Prose may name a package in order to say it is NOT a dependency, which
 * is exactly what the note under the table now does, and a check that could not tell the
 * difference would forbid saying so.
 */
it('lists no dependency composer does not have', function (): void {
    /** @var array<string, string> $manifest */
    $manifest = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $known = array_keys(($manifest['require'] ?? []) + ($manifest['require-dev'] ?? []));

    $doc = (string) file_get_contents(base_path('docs/requirements.md'));

    preg_match_all('/^\|\s*`([a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*)`/m', $doc, $matches);

    $phantom = array_values(array_diff(array_unique($matches[1]), $known));

    expect($matches[1])->not->toBeEmpty('the requirements.md table read broke');
    expect($phantom)->toBe([], 'requirements.md lists packages composer does not require: '.implode(', ', $phantom));
});
