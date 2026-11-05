<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveConsoleLocale;
use App\Platform\Locale\ConsoleLanguage;
use App\Platform\Locale\HostedLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final readonly class ConsoleLocaleController
{
    public function __invoke(Request $request, ConsoleLanguage $language): RedirectResponse
    {
        $request->validate(['locale' => ['required', 'string', Rule::in(HostedLocale::codes())]]);

        $language->save($request, HostedLocale::from($request->string('locale')->toString()));

        return back()->withoutCookie(ResolveConsoleLocale::COOKIE);
    }
}
