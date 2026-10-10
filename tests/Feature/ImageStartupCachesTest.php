<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;

/*
|--------------------------------------------------------------------------
| The caches the production image builds at every container start
|--------------------------------------------------------------------------
|
| Production's pods run with LARAVEL_AUTO_OPTIMIZE=true, so the base image's entrypoint runs
| `config:cache`, `route:cache` and `view:cache` against the pod's own environment before
| cbox-init starts nginx and PHP-FPM. Each step is `|| true` there: a step that throws does
| not stop the pod, it just leaves that cache unbuilt, and nothing reports it.
|
| `view:cache` threw on every start. Six console modules (analytics, compliance, connectors,
| devices, risk-plus, whitelabel) registered a Blade namespace for `resources/views`, a
| directory none of them has — their pages are Inertia components, and git does not keep an
| empty directory, so it never reaches a checkout or the image. `view:cache` walks every
| registered namespace with Symfony Finder, and Finder refuses a directory that is not there:
| "The ".../modules/analytics/src/../resources/views" directory does not exist."
| `php artisan optimize` failed the same way on any checkout.
|
| The fix is the registration, not the directory: a namespace with no templates behind it is
| a promise nothing keeps. This test holds every registered view location to existing, which
| is exactly the precondition `view:cache` has.
*/

it('registers no view location that does not exist, so view:cache can walk them all', function (): void {
    $finder = View::getFinder();

    $missing = [];

    foreach ($finder->getPaths() as $path) {
        if (! is_dir($path)) {
            $missing[] = $path;
        }
    }

    foreach ($finder->getHints() as $namespace => $paths) {
        foreach ($paths as $path) {
            if (! is_dir($path)) {
                $missing[] = "{$namespace}:: {$path}";
            }
        }
    }

    expect($missing)->toBe([]);
});
