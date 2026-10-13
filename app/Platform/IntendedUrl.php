<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Controllers\EnvironmentHandoffController;

/**
 * The page somebody was trying to reach before they were asked to sign in — and which of
 * the two identities on a tenant host it belongs to.
 *
 * ONE HOST, TWO IDENTITIES, ONE `url.intended`. A tenant environment's host serves both
 * the environment ADMIN console (an account member, whose session resolves in the platform
 * root) and the tenant's own END-USER pages (a subject inside the environment). They share
 * a cookie, so they share this key — and neither can open the other's pages.
 *
 * That is how an administrator got stuck. Opening `/device?user_code=…` — an end-user page
 * — while holding an admin session bounced to the tenant sign-in form, and Laravel's
 * `redirect()->guest()` recorded `/device` as the intent. The SSO handoff from the account
 * console then succeeded, established a perfectly good admin session, and honoured that
 * intent: straight back to `/device`, which a platform-root subject may not open, which
 * bounced to the tenant login and rewrote the intent again. The console was unreachable
 * from a browser that had once visited an end-user page, and nothing about it looked like
 * a bug — every hop was doing its job.
 *
 * So an intent is claimed by the plane that can actually serve it, and anything else is
 * dropped rather than followed into a loop.
 */
final class IntendedUrl
{
    /**
     * Laravel's own key, so `redirect()->guest()` and this class agree without either
     * having to know about the other. Public because the sign-in page READS it to say
     * why somebody is being asked to sign in — reading is not consuming, and consuming
     * it there would strand them on the dashboard afterwards.
     */
    public const KEY = 'url.intended';

    /** The admin console lives under this prefix on a tenant host. */
    private const ADMIN_PREFIX = '/admin';

    /**
     * The stamp that makes an intent to OPEN AN ENVIRONMENT'S CONSOLE honourable: which
     * door, and when this browser was sent to sign in on its way through it.
     */
    public const OPEN_STAMP_KEY = 'cbox.open_intent';

    /** How long a sign-in may take and still be on its way to the console it started at. */
    private const OPEN_STAMP_SECONDS = 15 * 60;

    /** The account host's open-environment door, `/open/{environment}` ({@see EnvironmentHandoffController}). */
    private const OPEN_PATH = '#\A/open/[A-Za-z0-9_-]+\z#';

    /**
     * The intended page for the environment ADMIN console, or null.
     *
     * Always consumes the stored value: an intent that this plane cannot serve is not one
     * to leave lying around for the next sign-in on the other plane to trip over.
     */
    public static function pullForAdminConsole(): ?string
    {
        return self::pull(static fn (string $path): bool => str_starts_with($path, self::ADMIN_PREFIX));
    }

    /** The intended page for an end-user (subject) session, or null. */
    public static function pullForSubject(): ?string
    {
        // ON THE PLATFORM ROOT'S OWN HOST, `/admin` is a subject's console too: the root
        // environment's, administered by the operators who sign in right here. An operator
        // who opened `cboxid.com/admin/social-sign-in` signed out must land back on it after
        // signing in — with a password, a passkey or Google — and there is no second
        // identity on that host for the intent to belong to instead.
        $rootConsole = self::onRootConsoleHost();

        $stamp = session()->pull(self::OPEN_STAMP_KEY);

        $intended = self::pull(static fn (string $path): bool => $rootConsole || ! str_starts_with($path, self::ADMIN_PREFIX));

        // AN ENVIRONMENT'S CONSOLE ONLY FOR SOMEBODY ON THEIR WAY TO IT. The open-environment
        // door mints a handoff into a tenant's console, and an intent naming it used to be
        // honoured whenever it was found — so a browser that had once bounced off a
        // tenant's `/admin`, and signed in at the root an hour later for some other reason,
        // was dropped into that tenant's console instead of the home it asked for. It is
        // honoured now only with a fresh stamp for that exact door, written when THIS
        // browser was sent to sign in from it ({@see rememberEnvironmentOpen()}). The
        // console's own environment switcher never meets this: it opens the door signed in.
        if ($intended !== null && self::opensAnEnvironment($intended) && ! self::stamped($intended, $stamp)) {
            return null;
        }

        return $intended;
    }

    /**
     * Stamp the intent just recorded as a sign-in on its way to open an environment's
     * console — called by the sign-in gate when it sends somebody from that door to sign in.
     */
    public static function rememberEnvironmentOpen(): void
    {
        $intended = session()->get(self::KEY);

        if (is_string($intended) && self::opensAnEnvironment($intended)) {
            session()->put(self::OPEN_STAMP_KEY, ['url' => $intended, 'at' => now()->getTimestamp()]);
        }
    }

    private static function opensAnEnvironment(string $intended): bool
    {
        $path = parse_url($intended, PHP_URL_PATH);

        return is_string($path) && preg_match(self::OPEN_PATH, $path) === 1;
    }

    private static function stamped(string $intended, mixed $stamp): bool
    {
        if (! is_array($stamp) || ! is_string($stamp['url'] ?? null) || ! is_int($stamp['at'] ?? null)) {
            return false;
        }

        $age = now()->getTimestamp() - $stamp['at'];

        return hash_equals($stamp['url'], $intended) && $age >= 0 && $age <= self::OPEN_STAMP_SECONDS;
    }

    /** Whether this request is on the platform root's own host, where `/admin` is the root environment's console. */
    private static function onRootConsoleHost(): bool
    {
        $request = request();

        return app(PlaneResolver::class)->onRootEnvironmentConsole($request->getHost());
    }

    /**
     * @param  callable(string): bool  $claims  whether this plane can serve that path
     */
    private static function pull(callable $claims): ?string
    {
        $intended = session()->pull(self::KEY);

        if (! is_string($intended) || $intended === '') {
            return null;
        }

        // The PATH, not the whole URL. `redirect()->guest()` stores an absolute URL, and
        // an absolute URL is also what an open redirect looks like — so the decision is
        // made on the path, and a value naming another host is refused outright rather
        // than reasoned about.
        $path = parse_url($intended, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (self::isOffHost($intended)) {
            return null;
        }

        // A BACKSLASH IS A SLASH TO A BROWSER. `parse_url('/\evil.test/x')` reports no
        // host and a path, so it passes every check above — and Chrome and Firefox
        // normalise it and treat the result as protocol-relative. Nothing writes a
        // user-supplied value into this key today; that is a fact about the callers, not a
        // property of this class, and this is the cheaper half of the argument.
        if (str_contains($path, '\\')) {
            return null;
        }

        return $claims($path) ? $intended : null;
    }

    private static function isOffHost(string $intended): bool
    {
        $host = parse_url($intended, PHP_URL_HOST);

        return is_string($host) && $host !== '' && $host !== request()->getHost();
    }
}
