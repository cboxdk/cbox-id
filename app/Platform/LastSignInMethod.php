<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * "LAST USED" — which way this DEVICE signed in last time, so the sign-in page can mark it.
 *
 * Somebody with a passkey, a Google button and a password in front of them at 7am does not
 * remember which one they set up; the page does. Clerk, WorkOS AuthKit and Stytch all put a
 * small "Last used" badge on that method, and so does this one: on each social provider's
 * button, the passkey, the magic link, and the email step for a password or single sign-on.
 *
 * WHAT IS STORED IS THE METHOD, AND NOTHING ELSE. `google`, `passkey`, `password`,
 * `magic_link`, `sso` — never who, never an address, never an identifier of the account. A
 * shared computer learns only that somebody on it used Google, which the button already
 * says is possible. It is a first-party cookie on the host the person signed in on — no
 * `Domain` attribute, so each environment's host keeps its own and one tenant's doors say
 * nothing about another's — encrypted like every cookie this application sets, and READ ON
 * THE SERVER, handed to the page as a prop. No script reads cookies to draw the badge, so
 * the cookie is HttpOnly as well.
 *
 * Written where every door converges — {@see PlatformAuth::establish()} and
 * {@see PlatformAuth::adopt()} — from the session's `amr`, so a new door is covered by
 * the rule rather than by somebody remembering. Only after a sign-in SUCCEEDED: a refused
 * passkey never becomes the "last used" one. Impersonation and an accepted invitation are
 * not ways this person chose to sign in, and leave the cookie as it was.
 */
final class LastSignInMethod
{
    public const string COOKIE = 'cbox_last_sign_in';

    /** About thirteen months — the ceiling browsers now put on any cookie (400 days). */
    private const int MINUTES = 60 * 24 * 395;

    public const string PASSWORD = 'password';

    public const string PASSKEY = 'passkey';

    public const string MAGIC_LINK = 'magic_link';

    public const string SSO = 'sso';

    /**
     * The method a session's `amr` describes, or null when it is not one a person chose at
     * a sign-in page.
     *
     * @param  list<string>  $amr
     */
    public static function fromAmr(array $amr): ?string
    {
        if (in_array('impersonation', $amr, true) || in_array('invitation', $amr, true)) {
            return null;
        }

        // `['social', 'google']` — the provider IS the method; its button is what is marked.
        if (($amr[0] ?? null) === 'social') {
            $provider = $amr[1] ?? null;

            return is_string($provider) && self::wellFormed($provider) ? $provider : null;
        }

        return match (true) {
            in_array('passkey', $amr, true) => self::PASSKEY,
            in_array('sso', $amr, true) => self::SSO,
            in_array('magic_link', $amr, true) => self::MAGIC_LINK,
            in_array('pwd', $amr, true) => self::PASSWORD,
            default => null,
        };
    }

    /**
     * Remember the method this sign-in used, on this host.
     *
     * @param  list<string>  $amr
     */
    public static function remember(array $amr): void
    {
        $method = self::fromAmr($amr);

        if ($method === null) {
            return;
        }

        // A Symfony cookie rather than `Cookie::make()`, which fills in the SESSION's
        // configured domain. That may name a parent domain; this cookie must be host-only.
        Cookie::queue(new SymfonyCookie(
            name: self::COOKIE,
            value: $method,
            expire: now()->addMinutes(self::MINUTES)->getTimestamp(),
            path: '/',
            domain: null,
            secure: request()->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: SymfonyCookie::SAMESITE_LAX,
        ));
    }

    /** The method this device used last, or null — a value that is not well-formed is no answer. */
    public static function read(Request $request): ?string
    {
        $value = $request->cookie(self::COOKIE);

        return is_string($value) && self::wellFormed($value) ? $value : null;
    }

    private static function wellFormed(string $value): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9_-]{0,39}\z/', $value) === 1;
    }
}
