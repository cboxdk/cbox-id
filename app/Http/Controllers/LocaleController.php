<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Locale\HostedLocales;
use App\Platform\Locale\LocaleResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * THE LANGUAGE PICKER'S ONE WRITE: remember the choice, go back to the page.
 *
 * A cookie rather than anything stored against an account, because most of the people
 * who use the picker are not signed in yet — that is what the pages it sits on are for —
 * and the `users` table is the identity package's to change, not ours
 * ({@see LocaleResolver}).
 *
 * The session's `ui_locales` memory is dropped in the same breath. It outranks the
 * cookie on purpose — a relying party's choice should survive a stale cookie from last
 * year — but a person who just picked a language by hand has said something newer than
 * the app that sent them, and the picker would otherwise appear to do nothing for the
 * rest of the authorization.
 */
final readonly class LocaleController
{
    /** A year: long enough to be a preference, short enough to expire with the device. */
    private const MINUTES = 60 * 24 * 365;

    public function __invoke(Request $request, HostedLocales $locales): RedirectResponse
    {
        $choice = $request->input('locale');
        $locale = $locales->accept(is_string($choice) ? $choice : null);

        // An unknown or switched-off language is not an error worth a message: the picker
        // only offers enabled ones, so this is a stale tab or a hand-made request, and the
        // page it came from is still the right answer.
        if ($locale === null) {
            return back();
        }

        if ($request->hasSession()) {
            $request->session()->forget(LocaleResolver::SESSION_KEY);
        }

        return back()->withCookie(cookie(
            name: LocaleResolver::COOKIE,
            value: $locale->value,
            minutes: self::MINUTES,
            sameSite: 'lax',
        ));
    }
}
