<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Props\Shared\HelpProps;
use App\Platform\Help\HelpTopic;
use App\Platform\Shell\IdShell;
use App\Platform\Theme;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * PREFERENCES: language, regional format, time zone and theme, on a page of their own.
 *
 * The same four choices the account menu at the rail's foot and ⌘K offer, saved the same
 * way (the page calls the layout's one saver): the language to the person's account, the
 * other three in this browser's cookies, read by the server before the first paint. A
 * page because people look for "settings" by name, and a menu is not where a person
 * expects to set a time zone.
 */
final readonly class AccountPreferencesController extends PageController
{
    public function index(Request $request): Response
    {
        return $this->page('account/preferences', __('console.account_preferences_title'), [
            'help' => HelpProps::for(HelpTopic::Preferences),
            'current' => [
                'theme' => Theme::preference() ?? 'system',
                'regionalFormat' => IdShell::regionalFormat($request->cookie(IdShell::FORMAT_COOKIE)),
                'timeZone' => IdShell::timeZone($request->cookie(IdShell::TIME_ZONE_COOKIE)),
            ],
        ]);
    }
}
