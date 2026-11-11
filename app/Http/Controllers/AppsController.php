<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use Illuminate\Http\RedirectResponse;

/**
 * Keep existing app-picker bookmarks: land on a page whose header has the switcher.
 *
 * That is the personal console's account page, where it stands in a console. In login-host
 * mode the account pages are on the login host, drawn without a console around them and so
 * without a switcher, and the console on the app host lands on its dashboard instead.
 */
final class AppsController
{
    public function __invoke(Surfaces $surfaces): RedirectResponse
    {
        return $surfaces->is(Surface::Console)
            ? to_route('dashboard', ['switch-app' => '1'])
            : to_route('account', ['switch-app' => '1']);
    }
}
