<?php

declare(strict_types=1);

namespace App\Platform\Locale;

use App\Http\Controllers\PageController;
use App\Http\Middleware\ResolveConsoleLocale;
use App\Http\Props\Shared\I18nProps;
use App\Platform\Appearance\BrandContext;
use Cbox\Id\UserProperties\ValueObjects\PropertySnapshot;
use Illuminate\Translation\Translator;

/**
 * The translations for each hosted or console page and its shared UI.
 *
 * A page names its group by being rendered ({@see PageController}),
 * the same way it names its layout: `auth/*` is the sign-in group, `oauth/*` the consent
 * group, `portal/*` the Admin Portal. Pages receive their own namespace plus shared
 * UI strings, flattened to dot keys. Console pages receive the console catalog.
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
    public const CLIENT_NAMESPACES = ['hosted', 'auth', 'oauth', 'portal', 'console', 'service_access', 'billing', 'errors'];

    /** @var list<string>|null */
    private ?array $namespaces = null;

    public function __construct(
        private readonly Translator $translator,
        private readonly HostedLocales $locales,
    ) {}

    /**
     * Choose the page catalog by component name. Shared primitives use console keys
     * on both hosted and authenticated pages.
     */
    public function forPage(string $component): void
    {
        $this->namespaces = self::namespacesFor($component);
    }

    /**
     * @return list<string>
     */
    public static function namespacesFor(string $component): array
    {
        return [...match (true) {
            in_array($component, ['billing::billing', 'console/projects/show'], true) => ['hosted', 'console', 'billing'],
            $component === 'account/services' => ['hosted', 'console', 'service_access'],
            $component === 'auth/sudo' => ['hosted', 'console'],
            str_starts_with($component, 'auth/') => ['hosted', 'auth', 'console'],
            str_starts_with($component, 'oauth/') => ['hosted', 'oauth', 'console'],
            str_starts_with($component, 'portal/') => ['hosted', 'portal', 'console'],
            default => ['hosted', 'console'],
        }, 'errors'];
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

        $snapshot = request()->attributes->get(ResolveConsoleLocale::ATTRIBUTE);
        $preference = $snapshot instanceof PropertySnapshot ? [
            'subjectId' => $snapshot->userId,
            'version' => $snapshot->version,
            'updateUrl' => route(request()->routeIs('platform.*') ? 'platform.locale.update' : (request()->is('admin', 'admin/*') ? 'environment.locale.update' : 'console.locale.update')),
        ] : null;

        return new I18nProps(
            locale: $locale,
            locales: $preference !== null ? HostedLocale::cases() : $this->locales->enabled(),
            messages: $messages,
            preference: $preference,
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
