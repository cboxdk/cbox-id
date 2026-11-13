<?php

declare(strict_types=1);

use App\Platform\Locale\HostedLocale;

/**
 * The devices module's own catalogue — what an approval push says — in every hosted
 * language, with exactly English's keys and placeholders. LangParityTest covers `lang/`;
 * this module ships its strings beside its code.
 */
const DEVICES_LANG_ROOT = __DIR__.'/../../modules/devices/lang';

/** @return array<string, string> */
function devicesPushLines(string $locale): array
{
    $path = DEVICES_LANG_ROOT."/{$locale}/push.php";

    expect(is_file($path))->toBeTrue("modules/devices/lang/{$locale}/push.php is missing");

    return require $path;
}

it('says every push line in every hosted language, with the same placeholders', function (string $locale): void {
    $english = devicesPushLines('en');
    $translated = devicesPushLines($locale);

    expect(array_keys($translated))->toBe(array_keys($english));

    foreach ($english as $key => $line) {
        preg_match_all('/:([a-z]+)/', $line, $ours);
        preg_match_all('/:([a-z]+)/', $translated[$key], $theirs);
        sort($ours[1]);
        sort($theirs[1]);

        expect(trim($translated[$key]))->not->toBe('', "{$locale}: {$key} is empty")
            ->and($theirs[1])->toBe($ours[1], "{$locale}: {$key} changes its placeholders");
    }
})->with(HostedLocale::codes());
