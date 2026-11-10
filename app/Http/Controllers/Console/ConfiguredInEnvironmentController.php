<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Middleware\EnforceRootHostConsole;
use App\Platform\Console\OrganizationLanding;
use App\Platform\Console\RootHostConsole;
use App\Platform\Console\ShellPayload;
use Inertia\Response;

/**
 * "THIS IS SET IN AN ENVIRONMENT'S CONSOLE" — what the platform root's host answers, on the
 * URL somebody asked for, when the page sets something an environment owns
 * ({@see RootHostConsole}).
 *
 * A page rather than a redirect, because the right destination depends on WHICH
 * environment they meant and only they know: the page names the thing they came for, says
 * where it lives, and offers the same page in each environment they may open — the
 * context switcher's own rows ({@see ShellPayload}), each carrying the page to land on.
 *
 * Reached only through {@see EnforceRootHostConsole}, never routed on its own.
 */
final readonly class ConfiguredInEnvironmentController extends ConsoleController
{
    public function show(string $route, ?string $twin): Response
    {
        $label = RootHostConsole::label($route) ?? __('console.this_page_ebf2023c');

        return $this->page('console/configured-in-environment', $label, [
            'label' => $label,
            // Whether each environment's link opens this very page there, rather than its
            // front page — the switcher's rows carry it when there is one.
            'samePage' => $twin !== null,
            'projectsHref' => $this->scope->capabilities()?->canReadEnvironments() === true ? route('projects') : null,
            'consoleHref' => app(OrganizationLanding::class)->url(),
            'platformHref' => $this->scope->isPlatformOperator() ? route('environment.home') : null,
        ]);
    }
}
