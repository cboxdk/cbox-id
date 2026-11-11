<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Platform\Console\OperatorPaths;
use App\Platform\Deprecations\RetiredNameUse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/admin/platform/{path?}` → `/admin/{path}`: platform admin's old addresses.
 *
 * Only a redirect; the page redirected to runs the operator gate itself. A read is a 302
 * with `Deprecation`, and counted ({@see RetiredNameUse}), so the old addresses can go
 * once they have seen no traffic for seven days — a cached 301 would hide that traffic. A
 * write from a v2 tab still open is a 308, so its method and body go along (a 302 would
 * turn an old tab's POST into a GET and lose it).
 */
final class PlatformAdminMovedController extends Controller
{
    public function __invoke(Request $request, ?string $path = null): RedirectResponse
    {
        $to = OperatorPaths::fromPlatformPath($path ?? '');
        $query = $request->getQueryString();

        $read = $request->isMethod('GET') || $request->isMethod('HEAD');
        $verb = $read ? '' : $request->method().' ';

        RetiredNameUse::record('console_url', $verb.'/admin/platform'.($path === null || $path === '' ? '' : '/'.trim($path, '/')), $verb.$to);

        $response = redirect()->to($to.($query === null || $query === '' ? '' : '?'.$query), $read ? 302 : 308);
        $response->headers->set('Deprecation', 'true');

        return $response;
    }
}
