<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\Locale\HostedLocale;
use App\Platform\Locale\HostedTranslations;

/**
 * The language a hosted page is drawn in, the languages its picker offers, and the
 * strings it needs — its own group's only. {@see HostedTranslations}.
 *
 * Null on every page that is not hosted, which is every console page: the console is
 * English and ships no catalogue.
 */
final readonly class I18nProps implements Prop
{
    /**
     * @param  list<HostedLocale>  $locales
     * @param  array<string, string>  $messages
     */
    public function __construct(
        public string $locale,
        public array $locales,
        public array $messages,
    ) {}

    /**
     * @return array{locale: string, locales: list<array{code: string, name: string}>, messages: array<string, string>|object}
     */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'locales' => array_map(
                static fn (HostedLocale $locale): array => ['code' => $locale->value, 'name' => $locale->nativeName()],
                $this->locales,
            ),
            // An object even when empty: a JSON `[]` would arrive in React as an array and
            // every lookup on it would be a lookup on the wrong type.
            'messages' => $this->messages === [] ? (object) [] : $this->messages,
        ];
    }
}
