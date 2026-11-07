<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\Locale\HostedLocale;

/**
 * The resolved page language, available languages and page/shared UI strings.
 * Signed-in console pages also include the versioned preference update context.
 */
final readonly class I18nProps implements Prop
{
    /**
     * @param  list<HostedLocale>  $locales
     * @param  array<string, string>  $messages
     * @param  array{subjectId: string, version: int, updateUrl: string}|null  $preference
     */
    public function __construct(
        public string $locale,
        public array $locales,
        public array $messages,
        public ?array $preference = null,
    ) {}

    /**
     * @return array{locale: string, locales: list<array{code: string, name: string}>, messages: array<string, string>|object, preference: array{subjectId: string, version: int, updateUrl: string}|null}
     */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'preference' => $this->preference,
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
