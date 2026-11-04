<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AppLauncher;
use Inertia\Response;

final readonly class AppsController extends PageController
{
    public function __invoke(AppLauncher $apps): Response
    {
        return $this->page('account/apps', 'Switch app', ['apps' => $apps->apps()]);
    }
}
