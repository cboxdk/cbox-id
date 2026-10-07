<?php

declare(strict_types=1);

namespace App\Platform\Locale;

use Illuminate\Http\Request;

/**
 * WHICH LANGUAGE A HOSTED PAGE IS DRAWN IN.
 *
 * Asked in order, first enabled answer wins ({@see LocaleSource}):
 *
 *  1. `ui_locales` on the request. OIDC Core §3.1.2.1 lets a relying party say which
 *     languages it wants the authorization UI in, space-separated, most preferred first.
 *     The app that sent the person here knows their language better than we do.
 *  2. The `ui_locales` of the authorization in progress. `/oauth/authorize` is one hop of
 *     several — sign-in, MFA, the organization picker, consent — and only the first
 *     carries the parameter. Without remembering it the relying party's choice would hold
 *     for one redirect and the person would finish in English.
 *  3. The `cbox_locale` cookie: the person picked a language on one of our pages.
 *  4. `Accept-Language`, in the browser's own q-order.
 *  5. The environment's default.
 *
 * WHY THERE IS NO "SIGNED-IN USER'S PREFERENCE" STEP. The `users` table belongs to the
 * identity package (and may be a host application's own table under another name), so
 * this app cannot add a column to it safely. The cookie is what remembers a person's
 * choice instead; a stored preference is a package change, not an app one.
 *
 * Every candidate passes through {@see HostedLocales::accept()}: an unknown tag, a
 * malformed one, and a real language this environment has switched off are all skipped
 * the same way, and the next source is asked.
 */
final readonly class LocaleResolver
{
    /** Set by the language picker; read back on every hosted page. */
    public const COOKIE = 'cbox_locale';

    /** Where the authorization's `ui_locales` choice is kept between hops. */
    public const SESSION_KEY = 'cbox_locale.ui_locales';

    public function __construct(private HostedLocales $locales) {}

    public function resolve(Request $request): ResolvedLocale
    {
        $fromParameter = $this->firstAccepted(self::uiLocales($request));

        if ($fromParameter !== null) {
            return new ResolvedLocale($fromParameter, LocaleSource::UiLocales);
        }

        if ($request->hasSession()) {
            $remembered = $request->session()->get(self::SESSION_KEY);

            $locale = $this->locales->accept(is_string($remembered) ? $remembered : null);

            if ($locale !== null) {
                return new ResolvedLocale($locale, LocaleSource::Authorization);
            }
        }

        $cookie = $request->cookie(self::COOKIE);
        $locale = $this->locales->accept(is_string($cookie) ? $cookie : null);

        if ($locale !== null) {
            return new ResolvedLocale($locale, LocaleSource::Cookie);
        }

        // Symfony's parser already orders by q-value and drops `q=0`, which is the part of
        // RFC 9110 §12.5.4 that matters here — a language the browser explicitly refuses
        // must not be picked because it was listed.
        $locale = $this->firstAccepted(array_values($request->getLanguages()));

        if ($locale !== null) {
            return new ResolvedLocale($locale, LocaleSource::AcceptLanguage);
        }

        return new ResolvedLocale($this->locales->default(), LocaleSource::EnvironmentDefault);
    }

    /**
     * A language somebody CHOSE — the relying party for this authorization, or the person
     * with the picker — and nothing inferred.
     *
     * For the error pages, which are shared with the English console and so do not follow
     * the browser's `Accept-Language` the way a hosted page does: a person who picked Danish
     * on the sign-in form should not meet "Your session expired" in English when that form
     * goes stale, and an administrator whose browser merely prefers Danish should not find
     * the console's 404 in it.
     */
    public function chosen(Request $request): ?HostedLocale
    {
        if ($request->hasSession()) {
            $remembered = $request->session()->get(self::SESSION_KEY);
            $locale = $this->locales->accept(is_string($remembered) ? $remembered : null);

            if ($locale !== null) {
                return $locale;
            }
        }

        $cookie = $request->cookie(self::COOKIE);

        return $this->locales->accept(is_string($cookie) ? $cookie : null);
    }

    /**
     * The tags of `ui_locales`, from the query or — for `/oauth/authorize` over POST,
     * which OIDC makes mandatory — the body.
     *
     * @return list<string>
     */
    private static function uiLocales(Request $request): array
    {
        $value = $request->input('ui_locales');

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($value)) ?: []));
    }

    /**
     * @param  list<string>  $tags
     */
    private function firstAccepted(array $tags): ?HostedLocale
    {
        foreach ($tags as $tag) {
            $locale = $this->locales->accept($tag);

            if ($locale !== null) {
                return $locale;
            }
        }

        return null;
    }
}
