<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| The "acting organization" does not come back.
|--------------------------------------------------------------------------
|
| The environment console kept one organization in the session — chosen in the console
| header — and sixteen pages narrowed themselves to it without saying so: a pasted link
| opened a different page for its reader, a second tab retargeted the first, and a create
| form sent people to the header before it would let them finish. It is a URL now: the
| organization a page ACTS ON is a route parameter (`console.org`), the one a list is
| NARROWED to is `?organization=`, and a form that creates something for one asks "For
| which organization?".
|
| This holds the line in the source, because the convenient way back is one helper with a
| familiar name, and nothing else would notice it returning.
*/

/** @return array<string, string> path => source */
function sourcesUnder(string ...$roots): array
{
    $out = [];

    foreach ($roots as $root) {
        if (! is_dir(base_path($root))) {
            continue;
        }

        foreach (Finder::create()->files()->in(base_path($root))->name(['*.php', '*.ts', '*.tsx']) as $file) {
            $out[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
        }
    }

    return $out;
}

it('never brings back actingOrganizationId, the session selection or its endpoints', function (): void {
    $forbidden = [
        'actingOrganizationId',
        'ActingOrganization',
        'actingOrganization',
        'acting-organization.',
        'SELECTION_KEY',
    ];

    // The scope's own choose/clear — not the consent screen's unrelated "which organization
    // is this sign-in for", which is the person's own answer about themselves.
    $selection = '/(scope(\(\))?|ConsoleScope::class\))->(choose|clear)Organization\(/';

    $found = [];

    foreach (sourcesUnder('app', 'routes', 'resources/js/pages', 'resources/js/ui', 'resources/js/chrome', 'resources/js/layouts', 'resources/js/types', 'modules') as $path => $source) {
        foreach ($forbidden as $needle) {
            if (str_contains($source, $needle)) {
                $found[] = "{$path}: {$needle}";
            }
        }

        if (preg_match($selection, $source) === 1) {
            $found[] = "{$path}: choose/clear on the console scope";
        }
    }

    expect($found)->toBe([]);
})->group('security');

it('never sends anybody to choose an organization somewhere else first', function (): void {
    // A form that creates something for one organization asks which, on the form.
    $found = [];

    foreach (sourcesUnder('app', 'resources/js') as $path => $source) {
        if (stripos($source, 'choose an organization') !== false) {
            $found[] = $path;
        }
    }

    expect($found)->toBe([]);
});
