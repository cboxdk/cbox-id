<?php

declare(strict_types=1);

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * ONE CONTROLLER, ONE COMPONENT PATH — and every path names a file that is there.
 *
 * An Inertia component path is a namespace, and nothing in the stack notices a collision in
 * it. Two controllers can render `console/members`, the routes both resolve, the type
 * checker is happy, and the second page simply replaces the first on disk — which is
 * exactly what happened here while the organization's roster was being ported: the file was
 * written over the Identity-platform Administrators page, and only an un-rebuilt bundle
 * made the recovery possible.
 *
 * Neither half of this can be caught by a test of either page. A page whose file was
 * replaced still renders — it renders the OTHER page — and a page whose file was never
 * written renders an error only in a browser, which the request-level suite cannot see.
 */
it('gives every rendered page its own component file', function (): void {
    $rendered = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Http/Controllers')));

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        // The one call every page goes through. Matched on the literal because it IS a
        // literal everywhere — a computed component name would be a page nobody can find
        // by grepping, which is its own problem.
        preg_match_all(
            '/\$this->page\(\s*[\'"]([a-zA-Z0-9\/_-]+)[\'"]/',
            (string) file_get_contents((string) $file),
            $matches,
        );

        foreach ($matches[1] as $component) {
            $rendered[$component][] = str_replace(app_path().'/', '', (string) $file);
        }
    }

    // A FLOOR, so a renamed helper or a moved directory cannot empty the sweep and report
    // a clean bill over nothing.
    expect(count($rendered))->toBeGreaterThan(40, 'the sweep found almost no pages; did the render call change name?');

    $collisions = [];
    $missing = [];

    foreach ($rendered as $component => $controllers) {
        if (count(array_unique($controllers)) > 1) {
            $collisions[] = $component.' ← '.implode(', ', array_unique($controllers));
        }

        if (! is_file(base_path('resources/js/pages/'.$component.'.tsx'))) {
            $missing[] = $component.' (rendered by '.implode(', ', array_unique($controllers)).')';
        }
    }

    expect($collisions)->toBe(
        [],
        "two controllers render the same component path, so one page's file replaces the other's:\n"
        .implode("\n", $collisions),
    );

    expect($missing)->toBe(
        [],
        "a controller renders a component with no file behind it:\n".implode("\n", $missing),
    );
});
