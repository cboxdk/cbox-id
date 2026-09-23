<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * A console page that moved to its one URL — answered with a 301 to where it lives now.
 *
 * The two consoles used to spell the same page two ways (`/sod-policies` beside
 * `/admin/conflict-rules`, `/hooks` beside `/admin/event-hooks`), so a link copied out of
 * one was nonsense in the other. Every page has one slug now, the same on both, and the old
 * spellings stay reachable because they are in bookmarks, runbooks and support replies.
 *
 * NOT `Route::permanentRedirect()`, for one reason: Laravel's redirect controller drops the
 * query string. `/environment-keys?environment=…` and `/clients?page=3` carry state the
 * person chose, and landing on the first page of the default tab is a silent regression.
 *
 * The destination is a path pattern stored on the route (`defaults('to', …)`); its
 * `{placeholders}` take the matched parameters by name, so a detail page keeps its id.
 * Nothing from the request can choose the host: the target is always a path on this one.
 */
final class MovedPageController extends Controller
{
    public function __invoke(Request $request, Route $route): RedirectResponse
    {
        $pattern = $route->defaults['to'] ?? null;

        abort_unless(is_string($pattern) && str_starts_with($pattern, '/'), 404);

        $path = $pattern;

        foreach ($route->parameters() as $name => $value) {
            if (is_string($value)) {
                $path = str_replace('{'.$name.'}', rawurlencode($value), $path);
            }
        }

        $query = $request->getQueryString();

        // A pattern with a placeholder the old route did not carry is a registration
        // mistake, and a `{client}` in a Location header is a broken link, not a page.
        abort_if(Str::contains($path, ['{', '}']), 404);

        return redirect()->to($path.($query === null || $query === '' ? '' : '?'.$query), 301);
    }
}
