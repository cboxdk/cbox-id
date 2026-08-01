<?php

declare(strict_types=1);

/**
 * Every COLOUR token a white-labelled page reaches is either emitted per tenant, or
 * listed below with the reason it deliberately is not.
 *
 * `AppearanceCss::modeVars()` overrides a fixed set. Anything outside it silently falls
 * back to the platform's own value, so a branded page renders half the customer's brand
 * and half ours — and nothing fails, which is what makes this class of bug recur. It has
 * now recurred twice: `--accent-strong` (fixed earlier today) and `--canvas`, which is
 * `.auth-shell`'s base surface, so every white-labelled sign-in page had the platform's
 * beige around the customer's form. Because `--foreground` IS emitted, a tenant whose
 * light-mode background is dark got near-white text on that beige at about 1.02:1.
 *
 * Colour only. Radii, shadows, durations and easings are platform craft, not brand, and
 * a tenant does not get to make the interface feel different — just look like theirs.
 */
it('emits every colour token a branded page reaches', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    $emitted = [];
    preg_match_all('/["\']--([a-z-]+):/', (string) file_get_contents(base_path('app/Platform/Appearance/AppearanceCss.php')), $m);
    $emitted = $m[1];

    /**
     * Deliberately platform-level, with the reason.
     *
     * @var array<string, string>
     */
    $exempt = [
        'success' => 'semantic status, not brand — a tenant does not get to make "worked" ambiguous',
        'success-soft' => 'as above',
        'success-strong' => 'as above',
        'warning' => 'as above',
        'warning-soft' => 'as above',
        'warning-strong' => 'as above',
        'destructive' => 'as above — an error must read as an error whatever the palette',
        'destructive-soft' => 'as above',
        'destructive-strong' => 'as above',
        'text' => 'an alias for --foreground, which IS emitted',
        'bg' => 'an alias for --background, which IS emitted',
        'surface' => 'an alias for --card, which IS emitted',
        'accent-fg' => 'an alias for --accent-foreground, which IS emitted',
        'nav-active-fg' => 'console chrome; no branded page reaches it',
    ];

    // What the auth layout and the shared controls on it actually reference.
    $reached = [];

    foreach (['components/layouts/auth.blade.php', 'components/layouts/portal.blade.php'] as $layout) {
        $path = base_path('resources/views/'.$layout);

        if (is_file($path)) {
            preg_match_all('/var\(--([a-z-]+)\)/', (string) file_get_contents($path), $hits);
            $reached = array_merge($reached, $hits[1]);
        }
    }

    foreach (['.auth-shell', '.input', '.btn', '.label', '.field-error', '.skip-link', '.card'] as $selector) {
        preg_match_all('/'.preg_quote($selector, '/').'[^{]*\{([^}]*)\}/', $css, $blocks);

        foreach ($blocks[1] as $block) {
            preg_match_all('/var\(--([a-z-]+)\)/', $block, $hits);
            $reached = array_merge($reached, $hits[1]);
        }
    }

    // Colour tokens only — anything whose name says it is a metric is not brand.
    $colourish = array_values(array_filter(array_unique($reached), fn (string $t): bool => ! preg_match('/^(radius|shadow|dur|ease|font|z)-?/', $t)));

    expect(count($colourish))->toBeGreaterThan(10, 'the token sweep found almost nothing — it broke');

    $unaccounted = array_values(array_diff($colourish, $emitted, array_keys($exempt)));

    expect($unaccounted)->toBe([], 'reached by a branded page, neither emitted per tenant nor exempted: '.implode(', ', $unaccounted));
});
