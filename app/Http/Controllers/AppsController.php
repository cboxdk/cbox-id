<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\EnterConsoleThroughStart;
use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use Illuminate\Http\RedirectResponse;

/**
 * Keep existing app-picker bookmarks: land on a page whose header has the switcher.
 *
 * That is the personal console's account page, where it stands in a console. In login-host
 * mode the account pages are on the login host, drawn without a console around them and so
 * without a switcher, and the console on the app host lands on its dashboard instead. The
 * login host sends the person there too (through the console's own sign-in, which the
 * redirect is given by {@see EnterConsoleThroughStart}): an app's
 * "switch app" link names the issuer, and the issuer is the login host.
 */
final class AppsController
{
    public function __invoke(Surfaces $surfaces): RedirectResponse
    {
        return match (true) {
            $surfaces->is(Surface::Login) => redirect()->away($surfaces->consoleUrl('/dashboard', ['switch-app' => '1'])),
            $surfaces->is(Surface::Console) => to_route('dashboard', ['switch-app' => '1']),
            default => to_route('account', ['switch-app' => '1']),
        };
    }
}
