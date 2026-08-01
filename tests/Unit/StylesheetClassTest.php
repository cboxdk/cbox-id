<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * A class name that exists in a view and in no stylesheet renders as nothing at all —
 * silently, and only on the screen that uses it.
 *
 * `error-text` was used on four validation messages and defined nowhere. It sat on the
 * forced password-change screen, which every administratively-provisioned user is made
 * to pass through, so the message they were meant to act on rendered as ordinary body
 * text: not red, not small, not visibly an error. Nothing in the suite noticed, because
 * a missing class is not a PHP error, not an HTTP error, and not an accessibility
 * violation an axe run can see.
 *
 * This checks only the project's own `cbx-`/design-system classes, not Tailwind's
 * utilities — Tailwind generates those on demand, so a utility absent from the built CSS
 * means it is unused, not undefined.
 */
it('defines every design-system class the views use', function (): void {
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/app.css');

    /** @var array<string, list<string>> $undefined  class => the files using it */
    $undefined = [];

    $roots = [__DIR__.'/../../resources/views', __DIR__.'/../../modules'];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (Finder::create()->files()->in($root)->name('*.blade.php') as $file) {
            $source = (string) file_get_contents((string) $file->getRealPath());

            preg_match_all('/class="([^"]*)"/', $source, $matches);

            foreach ($matches[1] as $attribute) {
                foreach (preg_split('/\s+/', $attribute) ?: [] as $class) {
                    // The project's own vocabulary: the cbx- prefix, plus the handful of
                    // unprefixed component classes the stylesheet defines.
                    $own = str_starts_with($class, 'cbx-')
                        || in_array($class, ['field-error', 'error-text', 'label', 'input', 'select', 'badge', 'card', 'btn'], true);

                    if (! $own || $class === '') {
                        continue;
                    }

                    if (! str_contains($css, '.'.$class)) {
                        $undefined[$class][] = str_replace([__DIR__.'/../../', 'resources/views/'], '', (string) $file->getRealPath());
                    }
                }
            }
        }
    }

    $report = implode('; ', array_map(
        static fn (string $class, array $files): string => $class.' ('.$files[0].(count($files) > 1 ? ' +'.(count($files) - 1) : '').')',
        array_keys($undefined),
        $undefined,
    ));

    expect(array_keys($undefined))->toBe([], 'used in a view, defined in no stylesheet: '.$report);
});
