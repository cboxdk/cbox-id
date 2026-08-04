<?php

declare(strict_types=1);

namespace App\Platform;

use Illuminate\Support\Facades\Request;

/**
 * Which theme this request should be PAINTED in, decided on the server.
 *
 * WHY THIS IS NOT JAVASCRIPT. The preference used to live in `localStorage` and be
 * applied by the bundled `app.js`. That bundle is an ES module, so it is deferred by
 * definition: it runs after the document is parsed and after the first paint. The page
 * therefore painted in whatever `@media (prefers-color-scheme)` said, and then flipped
 * when the script caught up — visible on every hard refresh to anyone whose choice
 * differs from their operating system's.
 *
 * `wire:navigate` made the same cause produce the opposite symptom. It re-renders the
 * document from the server's response, and the server's `<html>` carried no theme, so the
 * attribute the bundle had set was dropped on navigation — and the bundle, having already
 * run once, never put it back. The theme changed while walking between pages and stayed
 * changed.
 *
 * Neither is fixable in JavaScript, because the server cannot read `localStorage`. So the
 * preference moves to a cookie, which is the same answer the sidebar pin state already
 * uses for the same reason (`cbox-nav-pinned`, and see `bootstrap/app.php` for why it must
 * stay unencrypted: JS cannot write a Laravel-encrypted cookie). The first paint is then
 * already right, and there is nothing left for a script to correct.
 *
 * NO COOKIE MEANS NO ATTRIBUTE, deliberately — not "light". The stylesheet's default is
 * `@media (prefers-color-scheme: dark) { :root:not([data-theme='light']) }`, so an absent
 * attribute is what lets the operating system decide, which is the right answer for
 * somebody who has never expressed a preference. Writing a concrete value here would pin
 * every first-time visitor to one theme and ignore the OS entirely.
 */
final readonly class Theme
{
    /** The cookie the toggle writes. Unencrypted, because the toggle is client-side. */
    public const COOKIE = 'cbox-theme';

    /**
     * `data-theme="dark"`, `data-theme="light"`, or an empty string.
     *
     * Returns the whole attribute rather than the value so a layout cannot render
     * `data-theme=""` by interpolating an empty answer — which is not the same thing as
     * the attribute being absent, and would beat the `:not([data-theme='light'])` guard on
     * the media query in neither direction predictably.
     */
    public static function attribute(): string
    {
        $theme = self::preference();

        return $theme === null ? '' : ' data-theme="'.$theme.'"';
    }

    /**
     * The stated preference, or null when there is none.
     *
     * Validated against the two known values rather than echoed. The cookie is written by
     * client-side JavaScript and is therefore attacker-controlled on a machine somebody
     * else has touched; this value is interpolated into an HTML attribute, so anything
     * other than an exact match is discarded rather than escaped — there is no third
     * legitimate value to preserve.
     */
    public static function preference(): ?string
    {
        $value = Request::cookie(self::COOKIE);

        return $value === 'dark' || $value === 'light' ? $value : null;
    }
}
