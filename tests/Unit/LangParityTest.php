<?php

declare(strict_types=1);

use App\Platform\Locale\ConsoleStrings;
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
 * renders the literal text ":mail". Plural lines must carry the forms required by their language.
 *
 * Read with `require`, not through the translator: the translator merges the framework's
 * own English `auth.php` over ours and falls back to English for a missing line, which is
 * exactly the gap this test exists to see.
 */
const LANG_ROOT = __DIR__.'/../../lang';

const PARITY_NAMESPACES = ['hosted', 'auth', 'oauth', 'portal', 'mail', 'errors', 'service_access', 'registration', 'console', 'billing', 'shell'];

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
    preg_match_all('/(?:(?<![\pL\pN_]):|:(?=(?:value|part)\d+\b))([a-z][A-Za-z0-9_]*)/u', $line, $matches);

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

        expect($theirs)->not->toMatch('/__X\d+__|<end_of_turn>|<start_of_turn>/', "{$locale}: {$key} contains a translation marker");

        $alphabet = match ($locale) {
            'el' => '/[\p{Latin}\p{Greek}]/u',
            'bg', 'uk' => '/[\p{Latin}\p{Cyrillic}]/u',
            default => '/\p{Latin}/u',
        };
        preg_match_all('/\p{L}/u', $theirs, $letters);
        $unexpected = [];
        foreach (array_unique($letters[0]) as $letter) {
            if ($letter !== 'ʼ' && ! str_contains($line, $letter) && ! preg_match($alphabet, $letter)) {
                $unexpected[] = $letter;
            }
        }
        expect($unexpected)->toBe([], "{$locale}: {$key} contains an unexpected alphabet");

        expect(trim($theirs))->not->toBe('', "{$locale}: {$key} is empty")
            ->and(placeholdersIn($theirs))->toBe(placeholdersIn($line), "{$locale}: {$key} changes its placeholders")
            ->and(substr_count($theirs, '|'))->toBe(
                str_contains($line, '|') && in_array($locale, ['pl', 'ro', 'cs', 'hr', 'sk', 'uk'], true)
                    ? 2
                    : substr_count($line, '|'),
                "{$locale}: {$key} has the wrong number of plural forms",
            );
    }
})->with(otherLocales())->with(PARITY_NAMESPACES);

it('ships no catalogue English does not have', function (string $locale): void {
    $files = array_map(static fn (string $path): string => basename($path, '.php'), glob(LANG_ROOT."/{$locale}/*.php") ?: []);

    // validation.php is the framework's catalogue translated, not one of ours — the
    // framework ships the English and the next test holds it to that.
    expect(array_values(array_diff($files, [...PARITY_NAMESPACES, 'validation'])))->toBe([]);
})->with(otherLocales());

it('preserves usable protocol values and example URLs in setup instructions', function (string $locale): void {
    $english = catalogue('en', 'portal');
    $translated = catalogue($locale, 'portal');

    foreach ($english as $key => $line) {
        // Include angle-bracket host placeholders: they are part of these examples,
        // not HTML. Sentence punctuation is not part of a URL.
        preg_match_all('~https://[^\s)]+~u', $line, $matches);
        foreach ($matches[0] as $match) {
            $url = rtrim($match, '.,;');
            if ($url !== 'https://') {
                expect(str_contains($translated[$key], $url))->toBeTrue("{$locale}: {$key} changes an example URL");
            }
        }

        foreach (['X.509', 'SHA-256', 'roles/storage.objectCreator'] as $literal) {
            if (str_contains($line, $literal)) {
                expect(str_contains($translated[$key], $literal))->toBeTrue("{$locale}: {$key} changes a protocol value");
            }
        }
    }

    // These values are entered into the provider's configuration. Translating
    // `profile`, `user.email` or `nameIDFormatPrecedence` breaks the integration.
    $configurationValues = [
        'guides.sso.oidc.2' => ['openid', 'email', 'profile'],
        'guides.sso.okta.3' => ['user.email', 'user.firstName', 'user.lastName'],
        'guides.sso.shibboleth.0' => ['metadataURL', 'backingFile', 'FileBackedHTTPMetadataProvider'],
        'guides.sso.shibboleth.3' => ['RelyingPartyByName', 'nameIDFormatPrecedence', 'emailAddress'],
        'guides.sso.pingfederate.3' => ['SAML_SUBJECT', 'POST'],
    ];

    foreach ($configurationValues as $key => $literals) {
        foreach ($literals as $literal) {
            expect(str_contains($translated['portal.'.$key], $literal))->toBeTrue("{$locale}: {$key} changes a configuration value");
        }
    }
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

it('distinguishes placeholders from Swedish and Finnish colon inflections', function (): void {
    expect(placeholdersIn('API:er och :organization'))->toBe(['organization'])
        ->and(placeholdersIn(':provider:llä API:n kautta'))->toBe(['provider'])
        ->and(placeholdersIn('New :injected'))->toBe(['injected'])
        ->and(placeholdersIn('app:value0'))->toBe(['value0']);
});

it('preserves executable commands and permission examples in every language', function (string $locale): void {
    $messages = catalogue($locale, 'console');
    expect($messages['console.php_artisan_id_compliance_export_a7898bc2'])->toBe('php artisan id-compliance:export')
        ->and($messages['console.php_artisan_id_compliance_retention_b63e26c9'])->toBe('php artisan id-compliance:retention')
        ->and($messages['console.one_long_running_process_php_artisan_queue_a_9e4a3b67'])->toContain('php artisan queue:autoscale')
        ->and($messages['console.an_accountant_may_invoices_write_5f99429d'])->toContain('invoices:write')
        ->and($messages[ConsoleStrings::key('X-Cbox-Signature')])->toBe('X-Cbox-Signature')
        ->and($messages[ConsoleStrings::key('type, type:id or type:id#relation')])->toContain('type:id#relation')
        ->and($messages[ConsoleStrings::key('The scheduled export records a run here every five minutes once schedule:run is running and a sink is configured.')])->toContain('schedule:run');

    // Claim names and permission values are protocol data; the example descriptions are prose.
    $example = json_decode(str_replace(
        ':value1',
        '"invoices:write"',
        $messages['console.sub_the_person_s_id_org_the_organization_the_6eaa9420'],
    ), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($example))->toBe(['sub', 'org', 'roles', 'permissions'])
        ->and($example['sub'])->toBeString()->not->toBeEmpty()
        ->and($example['org'])->toBeString()->not->toBeEmpty()
        ->and($example['roles'])->toBe([':value0'])
        ->and($example['permissions'])->toBe(['invoices:write']);
})->with(otherLocales());

it('names passkeys distinctly from passwords in the additional languages', function (string $locale, string $term): void {
    $messages = catalogue($locale, 'console');
    $label = mb_strtolower($messages['console.add_passkey_a323d84f']);
    expect($label)->toContain($term)
        ->and($label)->not->toContain(mb_strtolower($messages[ConsoleStrings::key('Password')]));
})->with([
    ['de', 'passkey'], ['sv', 'passnyckel'], ['nb', 'passnøkkel'], ['fi', 'pääsyavai'],
    ['fr', 'clé'], ['es', 'clave de acceso'], ['it', 'passkey'], ['nl', 'passkey'],
]);
