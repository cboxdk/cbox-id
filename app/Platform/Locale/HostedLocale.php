<?php

declare(strict_types=1);

namespace App\Platform\Locale;

/**
 * A LANGUAGE THE HOSTED SURFACES CAN SPEAK — the closed set there is a catalogue for.
 *
 * An enum rather than a list of strings in config, because every place a locale arrives
 * from is somebody else's input: an OIDC `ui_locales` parameter, a cookie, a browser's
 * `Accept-Language`, an environment setting written by an older release. Each of those
 * is narrowed through {@see self::fromTag()} on the way in, and nothing downstream ever
 * holds a locale this file does not name — so a page cannot be asked to render in a
 * language whose `lang/` directory does not exist and fall back key by key into a
 * mixture.
 *
 * Adding a language is adding a case here, a `lang/{code}/` directory that passes
 * LangParityTest, and a line in docs/guides/languages.md.
 */
enum HostedLocale: string
{
    case English = 'en';
    case Danish = 'da';
    case German = 'de';
    case Swedish = 'sv';
    case NorwegianBokmal = 'nb';
    case French = 'fr';

    /**
     * The language's name IN ITSELF — what the picker shows.
     *
     * Never translated: a Danish speaker who landed on a German page must be able to find
     * "Dansk" in the list without reading German first.
     */
    public function nativeName(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Danish => 'Dansk',
            self::German => 'Deutsch',
            self::Swedish => 'Svenska',
            self::NorwegianBokmal => 'Norsk bokmål',
            self::French => 'Français',
        };
    }

    /**
     * A BCP 47 tag (or a POSIX-ish `da_DK`) narrowed to a supported language, or null.
     *
     * Only the primary subtag decides: `da-DK`, `da_DK` and `DA` are all Danish, because
     * the catalogue has one Danish and a region could not select anything else.
     *
     * `no` is the Norwegian MACROlanguage, and it is what a good share of Norwegian
     * browsers still send — mapped to Bokmål, which is the written standard nearly every
     * Norwegian reads. Nynorsk (`nn`) is deliberately NOT mapped: it is a distinct written
     * standard, and a Nynorsk reader whose browser lists only `nn` has said something we
     * should not overrule by guessing; their browser's next preference decides instead.
     */
    public static function fromTag(?string $tag): ?self
    {
        if ($tag === null) {
            return null;
        }

        $primary = strtolower(trim(strtok(str_replace('_', '-', $tag), '-') ?: ''));

        if ($primary === 'no') {
            return self::NorwegianBokmal;
        }

        return self::tryFrom($primary);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(static fn (self $locale): string => $locale->value, self::cases());
    }
}
