<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Deprecations\RetiredNameUse;
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

        return redirect()->to(url(self::SUCCESSOR), 308)->withHeaders([
            'Deprecation' => 'true',
            'Link' => '<'.url(self::SUCCESSOR).'>; rel="successor-version"',
        ]);
    }
}
