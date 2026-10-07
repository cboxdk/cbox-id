<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Illuminate\Http\Request;

/**
 * WHETHER THE RAIL IS DRAWN WITH ITS LABELS — pinned open, or the 52px strip of icons.
 *
 * PINNED IS THE DEFAULT. The icon strip asked a new administrator to learn a dozen glyphs
 * before they could find anything, and hovering every one of them to read its name is how
 * they learned. A label costs 158px and answers the question outright; the strip is for
 * the person who already knows the icons, and they can choose it.
 *
 * So the cookie records the EXCEPTION: only an explicit `0` — somebody unpinned it — draws
 * the strip. No cookie, an old `1`, anything else: labels. There is no per-person settings
 * store in this app to keep it in, and a preference about one browser window's layout is
 * reasonably a property of that browser.
 *
 * Read on the server, by the root view and the shell alike, so the first paint is already
 * the right width — a class applied by JavaScript arrives after it and animates the rail
 * open on every hard refresh. The cookie is excluded from encryption in
 * `bootstrap/app.php` because the browser writes it.
 */
final class NavPin
{
    public const COOKIE = 'cbox-nav-pinned';

    public static function pinned(?Request $request = null): bool
    {
        return ($request ?? request())->cookie(self::COOKIE) !== '0';
    }
}
