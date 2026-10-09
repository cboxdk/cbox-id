<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Support\DocsScreenshots;

/*
|--------------------------------------------------------------------------
| THE DOCS' SCREENSHOTS: EVERY ONE EMBEDDED, EVERY ONE THERE, EVERY ONE RETAKEN
|--------------------------------------------------------------------------
|
| The pictures are taken by tests/Browser/DocsScreenshotsTest.php, which only runs on its
| own (by hand, or from the "Docs screenshots" workflow on every release tag). This is
| the half that runs on every pull request, and needs no browser: it holds docs/ and the
| generator to one list, Tests\Support\DocsScreenshots::NAMES.
|
|  - A page that embeds a screenshot which does not exist shows a broken image.
|  - A file in docs/screenshots that no page embeds is a picture nobody looks at and
|    everybody has to keep current — four of them, when this was written.
|  - A file the generator does not take is one nobody retakes: it shows the console as it
|    was the day somebody captured it by hand.
|  - The catalogue (docs/screenshots/_index.md) lists each one with the page it shows.
*/

/**
 * Every screenshot a Markdown file under docs/ (or the README) embeds, as
 * `page → name` pairs. Matched on the file name, so `../screenshots/x.png`,
 * `screenshots/x.png` and an `<img src>` all count.
 *
 * @return list<array{0: string, 1: string}>
 */
function docsScreenshotReferences(): array
{
    $references = [];
    $pages = ['README.md'];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('docs'), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (str_ends_with($file->getPathname(), '.md')) {
            $pages[] = substr($file->getPathname(), strlen(base_path()) + 1);
        }
    }

    sort($pages);

    foreach ($pages as $page) {
        // The catalogue names every file, in backticks; it is checked on its own below.
        if ($page === DocsScreenshots::DIRECTORY.'/_index.md') {
            continue;
        }

        preg_match_all('#screenshots/([A-Za-z0-9_.-]+)\.png#', (string) file_get_contents(base_path($page)), $matches);

        foreach ($matches[1] as $name) {
            $references[] = [$page, $name];
        }
    }

    return $references;
}

/** @return list<string> */
function docsScreenshotFiles(): array
{
    $files = array_map(
        static fn (string $path): string => basename($path, '.png'),
        glob(base_path(DocsScreenshots::DIRECTORY.'/*.png')) ?: [],
    );

    sort($files);

    return $files;
}

it('has a file for every screenshot a docs page embeds', function (): void {
    $missing = [];

    foreach (docsScreenshotReferences() as [$page, $name]) {
        if (! is_file(base_path(DocsScreenshots::DIRECTORY.'/'.$name.'.png'))) {
            $missing[] = "{$page} → screenshots/{$name}.png";
        }
    }

    expect($missing)->toBe([]);
});

it('embeds every screenshot in at least one docs page', function (): void {
    $embedded = array_unique(array_map(static fn (array $reference): string => $reference[1], docsScreenshotReferences()));

    expect(array_values(array_diff(docsScreenshotFiles(), $embedded)))->toBe([]);
});

it('keeps exactly the screenshots the generator takes, so every one is retaken', function (): void {
    $names = DocsScreenshots::NAMES;
    sort($names);

    expect(array_unique(DocsScreenshots::NAMES))->toBe(DocsScreenshots::NAMES)
        // On disk but never retaken: a hand-made picture that will drift.
        ->and(array_values(array_diff(docsScreenshotFiles(), $names)))->toBe([])
        // On the list but not on disk: the generator was not run after adding it.
        ->and(array_values(array_diff($names, docsScreenshotFiles())))->toBe([]);

    // The generator takes each by name. A name it never saves is caught at run time too
    // (its last test checks every file on the list was written by that run); this is the
    // cheap version that fails on the pull request instead of on the release.
    $generator = (string) file_get_contents(base_path('tests/Browser/DocsScreenshotsTest.php'));

    foreach ($names as $name) {
        expect($generator)->toContain("'{$name}'");
    }
});

it('lists every screenshot in the catalogue, and nothing else', function (): void {
    preg_match_all('/^\|\s*`([A-Za-z0-9_.-]+)\.png`\s*\|/m', (string) file_get_contents(base_path(DocsScreenshots::DIRECTORY.'/_index.md')), $rows);

    $listed = $rows[1];
    sort($listed);
    $names = DocsScreenshots::NAMES;
    sort($names);

    expect($listed)->toBe($names);
});

/*
 * The workflow that retakes them. Its shape is what makes the pictures automatic AND safe:
 * on every release tag and on demand, in the image CI judges in, through a pull request —
 * never a push to main, which is a production deploy.
 */
it('retakes the screenshots on every release tag, in CI\'s image, through a pull request', function (): void {
    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile(base_path('.github/workflows/docs-screenshots.yml'));
    /** @var array<string, mixed> $ci */
    $ci = Yaml::parseFile(base_path('.github/workflows/ci.yml'));

    // YAML 1.1 reads a bare `on:` key as boolean true.
    $on = $workflow['on'] ?? $workflow[true] ?? [];
    expect($on)->toHaveKey('workflow_dispatch')
        ->and($on['push']['tags'] ?? [])->toBe(['v*'])
        ->and($on['push'])->not->toHaveKey('branches');

    $job = $workflow['jobs']['screenshots'];
    $ciImage = str_replace('${{ matrix.php }}', '8.5', (string) $ci['jobs']['test']['container']['image']);
    expect($job['container']['image'])->toBe($ciImage);

    $steps = collect($job['steps']);
    expect($steps->pluck('run')->filter()->implode("\n"))
        ->toContain('npm run build')
        ->toContain('vendor/bin/pest --group=docs-screenshots')
        ->not->toContain('git push');

    $proposal = $steps->first(static fn (array $step): bool => str_starts_with((string) ($step['uses'] ?? ''), 'peter-evans/create-pull-request@'));
    expect($proposal)->not->toBeNull()
        ->and($proposal['with']['base'])->toBe('main')
        ->and($proposal['with']['branch'])->not->toBe('main')
        ->and($proposal['with']['add-paths'])->toBe('docs/screenshots/*.png');

    // And the normal suite still never runs the generator, which writes into the tree.
    expect((string) file_get_contents(base_path('.github/workflows/ci.yml')))->toContain('--exclude-group=docs-screenshots')
        ->and((string) file_get_contents(base_path('phpunit.xml')))->toContain('<group>docs-screenshots</group>');
});
