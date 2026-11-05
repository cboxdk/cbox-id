<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Http\Controllers;

use Illuminate\Contracts\View\View;

/** No response-factory dependency during build-time route discovery. */
final class PrivacyController
{
    public function __invoke(): View
    {
        return view('authenticator-privacy');
    }
}
