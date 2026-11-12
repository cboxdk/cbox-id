<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Deprecations\RetiredNameUse;
use App\Platform\Surfaces\ConsoleEntry;
use App\Platform\Surfaces\SurfaceUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The v2 writes of a customer organization's own settings on a tenant host:
 * `PATCH /settings` and `DELETE /settings/organization` are `/organization` now, because
 * `/settings` is the person's own page on every host. ID is live and an old tab still has
 * these forms open, so they keep answering with a 308 (the method and body go along) and a
 * `Deprecation` header, and each use is counted ({@see RetiredNameUse}). Removed once they
 * have seen no traffic for seven days.
 */
final class MovedOrganizationSettingsController
{
    public const string SUCCESSOR = '/organization';

    public function __invoke(Request $request): RedirectResponse
    {
        $retired = $request->method().' /'.ltrim($request->path(), '/');

        RetiredNameUse::record('path', $retired, $request->method().' '.self::SUCCESSOR, ['host' => $request->getHost()]);

        // The host that serves the page, not this one: in login-host mode `/settings` is
        // the person's own page on the login host, and the organization's page is in the
        // console on the app host ({@see SurfaceUrls}), where `/organization` here is a 404.
        $successor = route('settings');

        $headers = [
            'Deprecation' => 'true',
            'Link' => '<'.$successor.'>; rel="successor-version"',
        ];

        if (parse_url($successor, PHP_URL_HOST) === $request->getHost()) {
            return redirect()->to($successor, 308)->withHeaders($headers);
        }

        // Another host holds another session, so the write cannot be carried over: the
        // browser is sent to the page instead (a 303 makes it a GET), through the
        // console's start so it opens as the person signed in here ({@see ConsoleEntry}).
        return redirect()->to(app(ConsoleEntry::class)->through($successor) ?? $successor, 303)->withHeaders($headers);
    }
}
