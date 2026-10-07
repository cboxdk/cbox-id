<?php

declare(strict_types=1);

namespace App\Platform\Locale;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Throwable;

/**
 * WHICH LANGUAGES THIS ENVIRONMENT'S HOSTED PAGES OFFER, and which one they fall back to.
 *
 * Two layers, the narrower winning:
 *
 *  - the DEPLOYMENT's, from `cbox-id.locales` (`CBOX_ID_DEFAULT_LOCALE`,
 *    `CBOX_ID_LOCALES`) — every environment starts here;
 *  - the ENVIRONMENT's own, in its `settings` JSON as `default_locale` and
 *    `enabled_locales` — the same bag the Appearance editor writes the sign-in theme
 *    into, because a language is part of how a vendor's sign-in page meets its users.
 *
 * Read once per request (scoped), and only when a hosted page or a mail asks. A console
 * page never does.
 *
 * THE INVARIANTS, held here so no caller has to: the enabled list is never empty, holds
 * only languages with a catalogue, and always contains the default. A settings blob that
 * breaks one of those — an older release, a hand edit — degrades to the deployment's
 * answer rather than to a page in no language at all.
 */
final class HostedLocales
{
    /** @var array{default: HostedLocale, enabled: list<HostedLocale>}|null */
    private ?array $resolved = null;

    public function __construct(private readonly EnvironmentContext $environments) {}

    public function default(): HostedLocale
    {
        return $this->resolve()['default'];
    }

    /** @return list<HostedLocale> */
    public function enabled(): array
    {
        return $this->resolve()['enabled'];
    }

    public function isEnabled(HostedLocale $locale): bool
    {
        return in_array($locale, $this->enabled(), true);
    }

    /**
     * A tag from outside — a parameter, a cookie, a header entry — accepted only if it
     * names a language this environment has switched on. A supported language the
     * environment has switched OFF is refused exactly like an unknown one: the vendor
     * turned it off because they cannot support people in it.
     */
    public function accept(?string $tag): ?HostedLocale
    {
        $locale = HostedLocale::fromTag($tag);

        return $locale !== null && $this->isEnabled($locale) ? $locale : null;
    }

    /**
     * @return array{default: HostedLocale, enabled: list<HostedLocale>}
     */
    private function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $settings = $this->environmentSettings();

        $enabled = self::parseList($settings['enabled_locales'] ?? null)
            ?? self::parseList(config('cbox-id.locales.enabled'))
            ?? HostedLocale::cases();

        $default = HostedLocale::fromTag(is_string($settings['default_locale'] ?? null) ? $settings['default_locale'] : null)
            ?? HostedLocale::fromTag(is_string(config('cbox-id.locales.default')) ? config('cbox-id.locales.default') : null)
            ?? HostedLocale::English;

        // A default the list does not contain would be offered by nothing and chosen by
        // everything. The list wins, and its first entry is the environment's answer.
        if (! in_array($default, $enabled, true)) {
            $default = $enabled[0];
        }

        return $this->resolved = ['default' => $default, 'enabled' => $enabled];
    }

    /**
     * A list of codes — an array from the settings JSON or a comma-separated string from
     * the environment — narrowed to supported languages, de-duplicated, order kept.
     * Null when nothing usable is left, so the next layer answers instead.
     *
     * @return non-empty-list<HostedLocale>|null
     */
    private static function parseList(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return null;
        }

        $locales = [];

        foreach ($value as $tag) {
            $locale = is_string($tag) ? HostedLocale::fromTag($tag) : null;

            if ($locale !== null && ! in_array($locale, $locales, true)) {
                $locales[] = $locale;
            }
        }

        return $locales === [] ? null : $locales;
    }

    /**
     * The host-pinned environment's settings bag, or an empty one.
     *
     * Guarded, because this is asked on the first-run screen of a deployment whose
     * database may not hold an environment yet — and a sign-in page that cannot decide
     * its language should render in the deployment's default, not answer 500.
     *
     * @return array<array-key, mixed>
     */
    private function environmentSettings(): array
    {
        try {
            $key = $this->environments->current()?->environmentKey();

            if ($key === null) {
                return [];
            }

            $settings = Environment::query()->whereKey($key)->value('settings');
        } catch (Throwable) {
            return [];
        }

        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        return is_array($settings) ? $settings : [];
    }
}
