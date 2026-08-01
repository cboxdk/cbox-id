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
