<?php

declare(strict_types=1);

namespace App\Platform\Locale;

use App\Http\Controllers\PageController;
use App\Http\Props\Shared\I18nProps;
use Illuminate\Translation\Translator;

/**
 * Per-surface catalogues shared with Inertia. Hosted groups receive their existing
 * namespaces; signed-in pages receive the console catalogue in the selected language.
 * Scoped to the request: PageController marks the component before props are resolved.
 * Missing translations fall back per key to English; catalogue parity is tested.
 */
final class HostedTranslations
{
    /**
     * The namespaces shipped to the browser, and the ones `php artisan i18n:types` reads.
     * `mail` and `errors` are rendered by PHP alone and never leave the server.
     *
     * @var list<string>
     */
    public const CLIENT_NAMESPACES = ['hosted', 'auth', 'oauth', 'portal', 'console'];

    /** @var list<string>|null */
    private ?array $namespaces = null;

    public function __construct(
        private readonly Translator $translator,
        private readonly HostedLocales $locales,
    ) {}

    /** Select the catalogue for the component being rendered. */
    public function forPage(string $component): void
    {
        $this->namespaces = self::namespacesFor($component);
    }

    /**
     * @return list<string>
     */
    public static function namespacesFor(string $component): array
    {
        return match (true) {
            $component === 'auth/sudo' => ['console', 'auth'],
            str_starts_with($component, 'auth/') => ['hosted', 'auth'],
            str_starts_with($component, 'oauth/') => ['hosted', 'oauth'],
            str_starts_with($component, 'portal/') => ['hosted', 'portal'],
            default => ['console'],
        };
    }

    public function toProps(): ?I18nProps
    {
        if ($this->namespaces === null) {
            return null;
        }

        $locale = app()->getLocale();
        $messages = [];

        foreach ($this->namespaces as $namespace) {
            $messages = [
                ...$messages,
                ...self::flatten($this->translator->getLoader()->load('en', $namespace), $namespace),
                ...self::flatten($this->translator->getLoader()->load($locale, $namespace), $namespace),
            ];
        }

        return new I18nProps(
            locale: $locale,
            locales: in_array('console', $this->namespaces, true)
                ? HostedLocale::cases()
                : $this->locales->enabled(),
            messages: $messages,
        );
    }

    /**
     * A nested `lang/` array as `namespace.dotted.key => line`.
     *
     * @param  array<array-key, mixed>  $lines
     * @return array<string, string>
     */
    public static function flatten(array $lines, string $prefix): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            $path = $prefix.'.'.$key;

            if (is_array($value)) {
                $flat = [...$flat, ...self::flatten($value, $path)];
            } elseif (is_string($value)) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
