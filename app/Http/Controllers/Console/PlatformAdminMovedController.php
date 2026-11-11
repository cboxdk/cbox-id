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
 * GET only and only a redirect; the page redirected to runs the operator gate itself.
 * 302 with `Deprecation`, and counted ({@see RetiredNameUse}), so the old addresses can go
 * once they have seen no traffic for seven days — a cached 301 would hide that traffic.
 */
final class PlatformAdminMovedController extends Controller
{
    public function __invoke(Request $request, ?string $path = null): RedirectResponse
    {
        $to = OperatorPaths::fromPlatformPath($path ?? '');
        $query = $request->getQueryString();

        RetiredNameUse::record('console_url', '/admin/platform'.($path === null || $path === '' ? '' : '/'.trim($path, '/')), $to);

        $response = redirect()->to($to.($query === null || $query === '' ? '' : '?'.$query), 302);
        $response->headers->set('Deprecation', 'true');

        return $response;
    }
}
