<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * `--accent` is a FILL. It is chosen to look right behind white or near-black, and it
 * carries `--accent-foreground` for exactly that. Used the other way round — as the
 * colour of a link, an icon or a label sitting ON the page — it fails.
 *
 * Measured on the shipped tokens: 3.08:1 against `--card` in dark and 2.82:1 against
 * `--accent-soft`, so every "View activity log" link, every soft-filled status pill and
 * every icon in that colour was below AA for body text — and below even the 3:1 large-
 * text floor on the soft fill. In LIGHT mode the same token is 13.41:1, which is why
 * nothing ever looked wrong to anyone reviewing in the default theme.
 *
 * `--accent-strong` is the same hue walked until it clears 4.5:1 (6.59:1 on the card in
 * dark, unchanged in light), and `AppearanceCss` now emits it per tenant so a
 * white-labeled page keeps the customer's colour rather than falling back to ours.
 *
 * `border-color` and `background` are untouched: contrast rules for a 1px edge and for
 * a fill are not the text rule, and the accent is doing its actual job there.
 */
it('never uses the fill accent as a text colour', function (): void {
    /** @var array<string, list<string>> $offenders  file => matched declarations */
    $offenders = [];

    foreach ([__DIR__.'/../../resources/views', __DIR__.'/../../modules'] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        foreach (Finder::create()->files()->in($root)->name('*.blade.php') as $file) {
            $source = (string) file_get_contents((string) $file->getRealPath());

            // `color:var(--accent)` but NOT border-color / background-color, and not
            // the -strong / -soft / -edge / -foreground siblings.
            preg_match_all('/(?<![a-z-])color:\s*var\(--accent\)/i', $source, $matches);

            if ($matches[0] !== []) {
                $offenders[$file->getRelativePathname()] = $matches[0];
            }
        }
    }

    /**
     * The one legitimate exception: the theme editor's live preview scopes its OWN
     * token block via `vars(mode)` in resources/js/app.js, so inside it `--accent` is
     * the TENANT's colour being previewed rather than the console's. That block defines
     * `--accent-strong` too — keep both in step if either changes.
     *
     * @var list<string>
     */
    $previewScoped = [];

    $unexpected = array_diff_key($offenders, array_flip($previewScoped));

    $report = implode('; ', array_map(
        static fn (string $file, array $hits): string => $file.' ×'.count($hits),
        array_keys($unexpected),
        $unexpected,
    ));

    expect(array_keys($unexpected))->toBe([], 'fill accent used as text (fails AA in dark): '.$report);
});
