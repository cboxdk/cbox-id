<?php

declare(strict_types=1);

namespace App\Platform;

use App\Http\Controllers\Auth\SignedOutController;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page drawn for a signed-in session must not outlive the session in the browser.
 *
 * Revoking a session ends what the SERVER will serve, and the gates re-ask on every
 * request — so a bookmark or a reload after signing out lands on a sign-in. What the server
 * never sees is the browser drawing a page it already has: Laravel's default `no-cache` lets
 * the back-forward cache and the HTTP cache keep the console's HTML, and Inertia keeps
 * every page's props in `history.state`, from which Back redraws a console page — names,
 * users, keys — without asking anyone.
 *
 *  - `Cache-Control: no-store` keeps the response out of both caches.
 *  - History encryption keeps Inertia's copy unreadable once the sign-out page has
 *    cleared the key ({@see SignedOutController::landing()}),
 *    and Inertia then asks the server, which answers with the sign-in.
 *
 * Applied by the gates that admit a signed-in session (the console's, and an environment
 * console's), so a public page is not made uncacheable for nothing.
 */
final class SignedInPage
{
    /** Directives that mean a response chose its own caching on purpose. */
    private const DELIBERATE = ['public', 'max-age', 's-maxage', 'immutable', 'no-store'];

    /**
     * Serve a signed-in request: its Inertia page encrypted in history, and its response
     * sealed against caching.
     *
     * The encryption switch is put back afterwards. Inertia keeps it on a container
     * singleton, which under Octane outlives the request, and a public page served next by
     * the same worker has no reason to inherit it.
     *
     * @param  Closure(Request): Response  $next
     */
    public static function serve(Request $request, Closure $next): Response
    {
        Inertia::encryptHistory();

        try {
            $response = $next($request);
        } finally {
            Inertia::encryptHistory((bool) config('inertia.history.encrypt', false));
        }

        return self::seal($response);
    }

    public static function seal(Response $response): Response
    {
        // PAGES, not assets. An image, a download or a stylesheet served behind the same gate
        // is not what Back redraws, and making one uncacheable only refetches it on every
        // render, which moves the layout under the person reading it.
        $type = (string) $response->headers->get('Content-Type');

        if (! $response->headers->has('X-Inertia') && ! str_starts_with($type, 'text/html')) {
            return $response;
        }

        foreach (self::DELIBERATE as $directive) {
            if ($response->headers->hasCacheControlDirective($directive)) {
                return $response;
            }
        }

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
