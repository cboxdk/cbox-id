<?php

declare(strict_types=1);

use App\Platform\Help\HelpTopic;

/**
 * A module that ships and is documented nowhere is not a feature, it is a surprise.
 *
 * `devices` shipped with six routes, seventeen environment variables and an artisan
 * command, and appeared in the docs exactly once: inside the frontmatter description of
 * the modules page, which said "six" while the table below it said "Five" and listed
 * five. Someone reading the docs could not discover the feature existed, and someone who
 * knew it existed could not find out how to turn it on.
 *
 * Directory listing rather than a hand-kept list, so a module added tomorrow is caught
 * by the same check.
 */
it('documents every module that ships', function (): void {
    $modules = array_map('basename', array_filter(glob(__DIR__.'/../../modules/*') ?: [], 'is_dir'));

    expect(count($modules))->toBeGreaterThan(3, 'module discovery broke');

    $docs = '';

    foreach (glob(__DIR__.'/../../docs/**/*.md') ?: [] as $file) {
        $docs .= (string) file_get_contents($file);
    }

    $undocumented = array_values(array_filter(
        $modules,
        static fn (string $module): bool => ! str_contains($docs, '`'.$module.'`'),
    ));

    expect($undocumented)->toBe([], 'modules with no docs entry: '.implode(', ', $undocumented));
});

/**
 * The "?" beside a page title offers "Read the guide" only when a guide exists — the
 * enum returns null otherwise, deliberately. A path pointing at a file that is not there
 * turns that affordance into a 404, which is worse than not offering it.
 */
it('points every help topic at a guide that exists', function (): void {
    $missing = [];

    foreach (HelpTopic::cases() as $topic) {
        $path = $topic->docsPath();

        if ($path !== null && ! is_file(__DIR__.'/../../docs/'.$path.'.md')) {
            $missing[] = $topic->value.' → docs/'.$path.'.md';
        }
    }

    expect($missing)->toBe([], 'help topics linking to a missing guide: '.implode('; ', $missing));
});
