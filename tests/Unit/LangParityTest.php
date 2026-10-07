<?php

declare(strict_types=1);

use App\Platform\Locale\HostedLocale;
use App\Platform\Locale\HostedTranslations;

/**
 * EVERY LANGUAGE SAYS EVERYTHING ENGLISH SAYS, AND NOTHING ELSE.
 *
 * English is the source of truth for which keys exist: React's `MessageKey` type is
 * generated from it, so a page cannot ask for a key English lacks. This is the other half
 * — a key English has and Swedish lacks would render as English in the middle of a
 * Swedish page, and a key only Swedish has is a translation of something that no longer
 * exists. Both directions fail here, per file, naming the key.
 *
 * PLACEHOLDERS MUST MATCH, because a translation that drops `:email` renders a sentence
 * that no longer says which address the link went to — and one that invents `:mail`
 * renders the literal text ":mail". Plural lines must keep their `one|other` shape.
 *
 * Read with `require`, not through the translator: the translator merges the framework's
 * own English `auth.php` over ours and falls back to English for a missing line, which is
 * exactly the gap this test exists to see.
 */
const LANG_ROOT = __DIR__.'/../../lang';

const PARITY_NAMESPACES = ['hosted', 'auth', 'oauth', 'portal', 'mail', 'errors'];

/** @return array<string, string> */
function catalogue(string $locale, string $namespace): array
{
    $path = LANG_ROOT."/{$locale}/{$namespace}.php";

    expect(is_file($path))->toBeTrue("lang/{$locale}/{$namespace}.php is missing");

    $lines = require $path;

    expect($lines)->toBeArray("lang/{$locale}/{$namespace}.php does not return an array");

    return HostedTranslations::flatten($lines, $namespace);
}

/** @return list<string> */
function placeholdersIn(string $line): array
{
    preg_match_all('/:([a-z][A-Za-z0-9_]*)/', $line, $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

/** @return list<string> */
function otherLocales(): array
{
    return array_values(array_filter(HostedLocale::codes(), static fn (string $code): bool => $code !== 'en'));
}

it('has exactly the English keys in every language', function (string $locale, string $namespace): void {
    $english = array_keys(catalogue('en', $namespace));
    $translated = array_keys(catalogue($locale, $namespace));

    expect(array_values(array_diff($english, $translated)))->toBe([], "missing from lang/{$locale}/{$namespace}.php")
        ->and(array_values(array_diff($translated, $english)))->toBe([], "in lang/{$locale}/{$namespace}.php but not in English");
})->with(otherLocales())->with(PARITY_NAMESPACES);

it('keeps every placeholder and plural form', function (string $locale, string $namespace): void {
    $english = catalogue('en', $namespace);
    $translated = catalogue($locale, $namespace);

    foreach ($english as $key => $line) {
        $theirs = $translated[$key] ?? null;

        if ($theirs === null) {
            continue; // reported by the key-parity test, once
        }

        expect(trim($theirs))->not->toBe('', "{$locale}: {$key} is empty")
            ->and(placeholdersIn($theirs))->toBe(placeholdersIn($line), "{$locale}: {$key} changes its placeholders")
            ->and(substr_count($theirs, '|'))->toBe(substr_count($line, '|'), "{$locale}: {$key} changes its plural forms");
    }
})->with(otherLocales())->with(PARITY_NAMESPACES);

it('ships no catalogue English does not have', function (string $locale): void {
    $files = array_map(static fn (string $path): string => basename($path, '.php'), glob(LANG_ROOT."/{$locale}/*.php") ?: []);

    // validation.php is the framework's catalogue translated, not one of ours — the
    // framework ships the English and the next test holds it to that.
    expect(array_values(array_diff($files, [...PARITY_NAMESPACES, 'validation'])))->toBe([]);
})->with(otherLocales());

/*
 * The framework's validation messages, which surface on every hosted form. The framework
 * ships them in English only, so each other language carries a full translation — every
 * rule, with the rule's own placeholders — rather than a hand-picked subset that silently
 * falls back to English the first time somebody adds a rule to a form.
 */
it('translates every framework validation message', function (string $locale): void {
    $english = HostedTranslations::flatten(
        require dirname(__DIR__, 2).'/vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php',
        'validation',
    );
    $translated = catalogue($locale, 'validation');

    // `custom.attribute-name.rule-name` is the framework's example, not a message.
    $english = array_filter($english, static fn (string $key): bool => ! str_starts_with($key, 'validation.custom.'), ARRAY_FILTER_USE_KEY);

    expect(array_values(array_diff(array_keys($english), array_keys($translated))))->toBe([], "missing from lang/{$locale}/validation.php");

    foreach ($english as $key => $line) {
        expect(placeholdersIn($translated[$key]))->toBe(placeholdersIn($line), "{$locale}: {$key} changes its placeholders");
    }
})->with(otherLocales());
