<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Middleware\RedirectIfAuthenticated;
use Illuminate\Http\Request;

/**
 * A SIGNED-IN BROWSER THAT IS SIGNING IN AGAIN: adding another account, or answering an
 * app's `prompt=login`, `max_age` or step-up, all of which send the person to the sign-in
 * page while a session is still open (`/settings/accounts/add`).
 *
 * That page posts to the same doors a guest's does (the address step, the password, a
 * passkey, a social provider, a sign-in link), and those doors stand behind the guest gate
 * ({@see RedirectIfAuthenticated}), which sends a signed-in browser to the console. So the
 * page rendered, and every button on it led to the dashboard: nobody could sign in again,
 * and an authorization that had asked for it was never resumed.
 *
 * Opening the page starts a short window in the session, and the gate lets a signed-in
 * browser through while it is open. The gate keeps people off pages that make no sense
 * for them; it protects nothing (a sign-in ADDS an account to the browser, by design), so
 * a window is enough and nothing has to close it but time.
 */
final class SigningInAgain
{
    private const string SESSION_KEY = 'cbox.signing_in_again';

    /** Long enough to find a password manager or a phone; short enough to mean "now". */
    private const int SECONDS = 15 * 60;

    public static function begin(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
    }

    public static function underWay(Request $request): bool
    {
        $since = $request->session()->get(self::SESSION_KEY);

        if (! is_int($since)) {
            return false;
        }

        $age = now()->getTimestamp() - $since;

        return $age >= 0 && $age <= self::SECONDS;
    }
}
