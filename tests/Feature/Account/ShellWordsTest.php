<?php

declare(strict_types=1);

use App\Platform\Locale\HostedTranslations;

/**
 * cbox-ui's `CboxShell` asks for its words in English and the console layout answers from
 * `lang/{locale}/shell.php` (resources/js/i18n/shell.ts). The rail, header and account
 * menu of a Danish page are Danish.
 */
it('ships the shell\'s words to every page drawn in the shell, and not to the doors', function (): void {
    expect(HostedTranslations::namespacesFor('account/security'))->toContain('shell')
        ->and(HostedTranslations::namespacesFor('account/services'))->toContain('shell')
        ->and(HostedTranslations::namespacesFor('auth/sudo'))->toContain('shell')
        ->and(HostedTranslations::namespacesFor('console/dashboard'))->toContain('shell')
        ->and(HostedTranslations::namespacesFor('auth/login'))->not->toContain('shell');
});

it('keeps the shell\'s placeholders in every language', function (string $locale): void {
    $english = require lang_path('en/shell.php');
    $lines = require lang_path("{$locale}/shell.php");
    $placeholders = static function (string $line): array {
        preg_match_all('/\{\w+\}/', $line, $found);
        $names = $found[0];
        sort($names);

        return $names;
    };

    expect(array_keys($lines))->toBe(array_keys($english));

    foreach ($english as $key => $line) {
        expect($placeholders($lines[$key]))->toBe($placeholders($line), "{$locale}: {$key}");
    }
})->with(fn (): array => array_values(array_filter(
    array_map('basename', glob(dirname(__DIR__, 3).'/lang/*', GLOB_ONLYDIR) ?: []),
    static fn (string $locale): bool => $locale !== 'en',
)));

it('speaks Danish in the shell of a Danish page', function (): void {
    $lines = require lang_path('da/shell.php');

    expect($lines)->toContain('Konto og præferencer', 'Skift app', 'Log ud');
});
