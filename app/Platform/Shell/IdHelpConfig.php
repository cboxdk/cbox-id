<?php

declare(strict_types=1);

namespace App\Platform\Shell;

use App\Assistant\SupportContext;
use Cbox\Console\Kit\Contracts\HelpConfig;
use Cbox\Console\Kit\Shell\ShellHelp;
use Illuminate\Http\Request;

/**
 * Help, through Cbox Assistant (laravel-assistant-client). The session URL is the same-origin
 * route the `<cbox-support>` element fetches its session from; it exists only when support is
 * configured and this person may open it, and without it Help says it is not available here.
 * Read from config and the session only: the browser calls Assistant after first paint.
 */
final readonly class IdHelpConfig implements HelpConfig
{
    public function resolve(Request $request): ShellHelp
    {
        $session = app(SupportContext::class)->sessionUrl($request);
        $portal = config('ai-assistant-connector.support.portal_url');

        return new ShellHelp(
            enabled: $session !== null,
            sessionUrl: $session,
            portalUrl: is_string($portal) && str_starts_with($portal, 'https://') ? $portal : null,
        );
    }
}
