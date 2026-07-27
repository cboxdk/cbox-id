<?php

declare(strict_types=1);

use Cbox\Id\Whitelabel\Support\PaletteTokens;

it('maps a raw palette to prefixed CSS custom-property tokens', function (): void {
    $tokens = PaletteTokens::normalize([
        'primary' => '#0A2540',
        'ring' => 'oklch(0.45 0.16 258)',
        'unknown' => '#ffffff', // not a brandable token -> dropped
    ]);

    expect($tokens)->toBe([
        '--primary' => '#0a2540',      // hex lower-cased
        '--ring' => 'oklch(0.45 0.16 258)',
    ]);
});

it('accepts hex and oklch colours', function (string $value): void {
    expect(PaletteTokens::isValidColor($value))->toBeTrue();
})->with([
    '#abc',
    '#0a2540',
    '#0a2540ff',
    'oklch(0.45 0.16 258)',
    'oklch(0.45 0.16 258 / 0.3)',
    'oklch(45% 0.16 258)',
]);

it('rejects anything that is not a hex or oklch colour (deny-by-default)', function (string $value): void {
    expect(PaletteTokens::isValidColor($value))->toBeFalse();

    // And such a value never survives into the token map.
    expect(PaletteTokens::normalize(['primary' => $value]))->toBe([]);
})->with([
    'named colour' => 'red',
    'rgb with semicolon' => 'rgb(0,0,0);',
    'declaration injection' => '#fff;position:fixed',
    'style breakout' => '#fff}body{display:none',
    'url()' => 'url(https://evil.example/x.png)',
    'css var expr' => 'var(--x)',
    'javascript' => 'javascript:alert(1)',
    'empty' => '',
]);
