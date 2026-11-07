<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Locale\ConsolePreferences;
use App\Platform\Locale\HostedLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ConsoleLocaleController
{
    public function __invoke(Request $request, ConsolePreferences $preferences): RedirectResponse
    {
        $request->validate([
            'subject_id' => ['required', 'string'],
            'version' => ['required', 'integer', 'min:0'],
            'language' => ['required', Rule::enum(HostedLocale::class)],
        ]);
        $language = HostedLocale::from($request->string('language')->toString());
        try {
            $preferences->update($request->string('subject_id')->toString(), $request->integer('version'), $language);
        } catch (ConflictHttpException) {
            throw ValidationException::withMessages(['language' => __('errors.locale_conflict')]);
        }

        return back(303);
    }
}
