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

/**
 * iOS zooms the viewport when a focused control's text is under 16px, and does not zoom
 * back out. The sign-in field carries `autofocus`, so this fired on load: the page
 * arrived magnified and shifted, with the form running off the right edge, before anyone
 * had touched it.
 *
 * The rule is easy to lose. It is one media query at the end of a 1000-line stylesheet,
 * it changes nothing any desktop reviewer can see, and the obvious "fix" if it goes
 * missing again is `maximum-scale=1` on the viewport meta — which works by taking
 * pinch-zoom away from everyone (WCAG 2.2 1.4.4), on the one page where someone most
 * needs to magnify a password field.
 *
 * So this asserts both halves: the sizing is there, and the meta tag has not been
 * "fixed" the other way.
 */
it('sizes touch controls at the threshold that stops iOS zooming, without disabling zoom', function (): void {
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/app.css');

    expect($css)->toMatch('/@media\s*\(pointer:\s*coarse\)/');

    // The declarations inside that block, whatever else it grows to contain.
    preg_match('/@media\s*\(pointer:\s*coarse\)\s*\{(.+?)\n\}/s', $css, $block);

    $declarations = $block[1] ?? '';

    // `toContain` is variadic — a second argument is another needle, not a message — so
    // the reason lives on `toBeTrue` instead, where it is actually printed.
    expect(str_contains($declarations, 'font-size: 16px'))
        ->toBeTrue('a control under 16px still zooms the page on iOS');

    expect($declarations)
        ->toContain('textarea')
        ->toContain('select')
        // Element selectors, not `.input`: the trigger is the control, so a bare
        // <input> in a plugin view has to be covered too.
        ->toMatch('/(^|[\s,])input:not/');

    foreach (Finder::create()->files()->in(__DIR__.'/../../resources/views')->name('*.blade.php') as $file) {
        $source = (string) file_get_contents((string) $file->getRealPath());

        if (! str_contains($source, 'name="viewport"')) {
            continue;
        }

        $disablesZoom = str_contains($source, 'maximum-scale') || str_contains($source, 'user-scalable');

        expect($disablesZoom)
            ->toBeFalse($file->getFilename().' disables pinch zoom (WCAG 2.2 1.4.4)');
    }
});

/**
 * The type-to-confirm field has to be completable on a phone.
 *
 * iOS capitalises the first letter of a text field and autocorrects it unless told not
 * to, so a required token like `grace@acme.test` is typed as `Grace@acme.test` and an
 * exact comparison can never succeed. The confirm dialog gates roughly twenty-five
 * destructive actions across the console; without these attributes none of them can be
 * completed from a phone, and the only signal was a button that stayed disabled.
 *
 * Asserted on the source rather than in a browser because the failure is a mobile
 * keyboard behaviour no headless engine reproduces — the attribute is the whole fix, so
 * the attribute is what gets guarded.
 */
it('keeps the confirm-to-delete field typable on a mobile keyboard', function (): void {
    $markup = (string) file_get_contents(
        __DIR__.'/../../resources/views/components/confirm-delete.blade.php'
    );

    expect(str_contains($markup, 'autocapitalize="none"'))
        ->toBeTrue('iOS capitalises the first letter, so the exact match can never succeed');

    expect(str_contains($markup, 'autocorrect="off"'))
        ->toBeTrue('autocorrect rewrites the token as the person types it');

    // A disabled button that explains nothing is the other half of the bug.
    expect($markup)->toContain('aria-describedby');
});

/**
 * A panel header must stack before it squeezes.
 *
 * `.cbx-panel-header` puts the title block and the action side by side, and neither
 * child wraps — the action is `shrink-0`, so at 375px the title and its description
 * absorb the whole shortfall. The Passkeys panel rendered its description four words
 * wide beside a button at full width; measured, the description had 184px of a 343px
 * card and the button had the rest.
 *
 * The pattern is on roughly forty console pages, so this one rule is the difference
 * between the console being usable on a phone and not. Guarded because it is a single
 * media query at the end of a long stylesheet that no desktop review will ever notice
 * is missing.
 */
it('stacks the panel header instead of squeezing it on a narrow screen', function (): void {
    $css = (string) file_get_contents(__DIR__.'/../../resources/css/app.css');

    // There is more than one 640px block in this file, and only one of them is this
    // rule — so match on the block that actually names the selector rather than on the
    // first one that happens to share the breakpoint.
    preg_match_all('/@media\s*\(max-width:\s*640px\)\s*\{(.+?)\n\}/s', $css, $blocks);

    $declarations = implode("\n", array_filter(
        $blocks[1] ?? [],
        static fn (string $block): bool => str_contains($block, '.cbx-panel-header'),
    ));

    expect(str_contains($declarations, '.cbx-panel-header'))
        ->toBeTrue('the panel header has no narrow-screen rule, so its description collapses');

    expect(str_contains($declarations, 'flex-direction: column'))
        ->toBeTrue('stacking is the fix — shrinking the text is the bug');
});
