<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveConsoleLocale;
use App\Platform\Locale\HostedLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final readonly class ConsoleLocaleController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate(['locale' => ['required', 'string', Rule::in(HostedLocale::codes())]]);

        return back()->withCookie(cookie(
            name: ResolveConsoleLocale::COOKIE,
            value: $request->string('locale')->toString(),
            minutes: 60 * 24 * 365,
            sameSite: 'lax',
        ));
    }
}
