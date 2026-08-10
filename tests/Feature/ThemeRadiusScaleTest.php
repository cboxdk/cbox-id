<?php

declare(strict_types=1);

use App\Platform\Appearance\Appearance;
use App\Platform\Appearance\AppearanceCss;
use App\Platform\Appearance\ThemeRadius;

/**
 * THE CORNER CONTROL DRESSES EVERY CORNER, not a third of them.
 *
 * The stylesheet carries a three-step scale — `--radius` for cards and dialogs,
 * `--radius-md` for buttons and inputs, `--radius-sm` for badges and chips — and the theme
 * overrode only the first. So choosing "Square" squared the cards and left every button,
 * field and badge at their fixed 8px and 6px. The setting was not ignored; it half-worked,
 * which reads as broken and is harder to diagnose.
 *
 * The live preview in `resources/js/app.js` had the same defect, so it agreed with the
 * result and confirmed the bug rather than exposing it. `radiusScale()` there mirrors
 * `ThemeRadius::scale()` here; changing one without the other puts the lie back.
 */
it('emits the whole radius scale, not just the card radius', function (): void {
    $css = (string) AppearanceCss::render(Appearance::fromArray(['radius' => ThemeRadius::None->value]));

    expect($css)->toContain('--radius:0rem')
        ->and($css)->toContain('--radius-md:0rem')
        ->and($css)->toContain('--radius-sm:0rem');
});

it('keeps the steps proportional so one choice stays coherent', function (): void {
    $scale = ThemeRadius::ExtraLarge->scale();

    // 1rem chosen → 0.6667 for buttons → 0.5 for badges: the ratio the hand-tuned
    // defaults already used (12 / 8 / 6).
    expect($scale['radius'])->toBe('1rem')
        ->and($scale['md'])->toBe('0.6667rem')
        ->and($scale['sm'])->toBe('0.5rem');
});

it('squares everything at the square end, with no stray decimals', function (): void {
    $scale = ThemeRadius::None->scale();

    expect($scale)->toBe(['radius' => '0rem', 'md' => '0rem', 'sm' => '0rem']);
});
