<?php

declare(strict_types=1);

namespace App\Platform\Locale;

use App\Http\Controllers\PageController;
use App\Http\Props\Shared\I18nProps;
use App\Platform\Appearance\BrandContext;
use Illuminate\Translation\Translator;

/**
 * THE CATALOGUE A HOSTED PAGE IS HANDED — its own group's strings, and nothing else.
 *
 * A page names its group by being rendered ({@see PageController}),
 * the same way it names its layout: `auth/*` is the sign-in group, `oauth/*` the consent
 * group, `portal/*` the Admin Portal. Each gets the shared chrome (`hosted`) plus its own
 * namespace, flattened to dot keys — so the login page carries the sign-in strings and
 * not the Admin Portal's, and a console page carries nothing at all (the prop is null and
 * costs nothing).
 *
 * FALLBACK IS PER KEY, to English. LangParityTest keeps every catalogue complete, so in a
 * release this never fires; it is what keeps a key added in English and not yet
 * translated from rendering as `auth.login.title` in the meantime.
 *
 * Scoped to the request, like {@see BrandContext}: the controller
 * marks the page, the shared-prop closure reads the mark when Inertia builds the response.
 */
final class HostedTranslations
{
    /**
     * The namespaces shipped to the browser, and the ones `php artisan i18n:types` reads.
     * `mail` and `errors` are rendered by PHP alone and never leave the server.
     *
     * @var list<string>
     */
    public const CLIENT_NAMESPACES = ['hosted', 'auth', 'oauth', 'portal'];

    /** @var list<string>|null */
    private ?array $namespaces = null;

    public function __construct(
        private readonly Translator $translator,
        private readonly HostedLocales $locales,
    ) {}

    /**
     * The page being rendered, by component name. Pages outside the hosted groups — the
     * console, and the two console-chromed pages under `auth/` and at the root (sudo,
     * device approval) — mark nothing and get no catalogue.
     */
    public function forPage(string $component): void
    {
        $this->namespaces = self::namespacesFor($component);
    }

    /**
     * @return list<string>|null
     */
    public static function namespacesFor(string $component): ?array
    {
        return match (true) {
            $component === 'auth/sudo' => null,
            str_starts_with($component, 'auth/') => ['hosted', 'auth'],
            str_starts_with($component, 'oauth/') => ['hosted', 'oauth'],
            str_starts_with($component, 'portal/') => ['hosted', 'portal'],
            default => null,
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
            locales: $this->locales->enabled(),
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
