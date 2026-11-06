<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use Illuminate\Http\RedirectResponse;

final class LegacyPlatformOrganizationController
{
    public function __invoke(?string $organization = null): RedirectResponse
    {
        return redirect()->route($organization === null ? 'platform.organizations' : 'platform.organization',
            array_filter(['targetEnvironment' => 'root', 'organization' => $organization]));
    }
}
