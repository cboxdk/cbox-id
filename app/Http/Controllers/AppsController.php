<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/** Keep existing app-picker bookmarks in the personal console. */
final class AppsController
{
    public function __invoke(): RedirectResponse
    {
        return to_route('account', ['switch-app' => '1']);
    }
}
