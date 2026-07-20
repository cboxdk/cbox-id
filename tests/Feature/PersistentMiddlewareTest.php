<?php

declare(strict_types=1);

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateAccountApi;
use App\Http\Middleware\AuthenticateAccountMember;
use App\Http\Middleware\AuthenticateEnvironmentAdmin;
use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\EnforceImpersonationWindow;
use App\Http\Middleware\EnforcePlane;
use App\Http\Middleware\PortalSession;
use App\Http\Middleware\RequireScope;
use App\Http\Middleware\RequireSudo;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetEnvironment;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;

/**
 * Livewire re-runs only *persistent* middleware on POST /livewire/update. Any route-level
 * auth guard that is NOT registered as persistent silently stops enforcing the moment a
 * component performs an action — the first page load is guarded, every action after it is not.
 *
 * That is not a theoretical gap: with `env.admin` missing from the list, the whole environment
 * control plane (49 components, 45 of which carry no in-component check) answered unauthenticated
 * action requests, and the snapshot checksum is keyed on APP_KEY — identical across tenant hosts —
 * so a snapshot captured in one tenant replayed against another's host.
 *
 * These tests guard the LIST itself rather than any one component, so a newly-added guard cannot
 * be forgotten here.
 */

/** @return list<class-string> the middleware Livewire will re-run on /livewire/update */
function persistentMiddleware(): array
{
    $property = new ReflectionProperty(PersistentMiddleware::class, 'persistentMiddleware');
    $property->setAccessible(true);

    /** @var list<class-string> $value */
    $value = $property->getValue();

    return $value;
}

/**
 * Route-level app middleware that deliberately need NOT be persistent, each with the reason.
 * Anything else in App\Http\Middleware guarding a web route must survive a Livewire action.
 *
 * @return array<class-string, string>
 */
function nonPersistentByDesign(): array
{
    return [
        // Runs in the global `web` group, so it is applied to /livewire/update anyway.
        SetEnvironment::class => 'global web group',
        // Response-header concern only; nothing to re-enforce per action.
        SecurityHeaders::class => 'response headers, not a gate',
        // API-only guards: token/scope auth on stateless routes that never serve Livewire.
        AuthenticateAccountApi::class => 'API routes only',
        AuthenticateEnvironmentApi::class => 'API routes only',
        RequireScope::class => 'API routes only',
    ];
}

it('re-runs every console auth guard on a Livewire action', function (): void {
    // These five were missing, which left the environment-admin console, the account plane,
    // the plane bulkheads, the sudo step-up and the impersonation block unenforced on
    // /livewire/update. BlockDuringImpersonation was found by the invariant test below, not
    // by inspection — which is the reason that test exists.
    expect(persistentMiddleware())
        ->toContain(AuthenticateEnvironmentAdmin::class)
        ->toContain(AuthenticateAccountMember::class)
        ->toContain(EnforcePlane::class)
        ->toContain(RequireSudo::class)
        ->toContain(BlockDuringImpersonation::class)
        // …and the ones already registered stay registered.
        ->toContain(Authenticate::class)
        ->toContain(AuthenticateOperator::class)
        ->toContain(EnforceImpersonationWindow::class)
        ->toContain(PortalSession::class);
});

it('registers EVERY app middleware guarding a web route as persistent', function (): void {
    $aliases = Route::getMiddleware();
    $persistent = persistentMiddleware();
    $exempt = nonPersistentByDesign();

    /** @var array<class-string, list<string>> $unguarded */
    $unguarded = [];

    foreach (Route::getRoutes() as $route) {
        // Only stateful web routes can carry a Livewire component.
        if (! in_array('web', $route->gatherMiddleware(), true)) {
            continue;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            // Strip parameters (`plane:account` → `plane`) and resolve the alias.
            $name = str_contains($middleware, ':') ? strstr($middleware, ':', true) : $middleware;
            $class = $aliases[$name] ?? $name;

            if (! is_string($class) || ! str_starts_with($class, 'App\\Http\\Middleware\\')) {
                continue;
            }

            if (isset($exempt[$class]) || in_array($class, $persistent, true)) {
                continue;
            }

            $unguarded[$class][] = $route->uri();
        }
    }

    expect($unguarded)->toBe(
        [],
        'These app middleware guard a web route but are NOT persistent, so they stop enforcing on '
        .'every Livewire action: '.implode(', ', array_keys($unguarded)).'. Either register them in '
        .'PlatformServiceProvider::boot() or document the exemption in nonPersistentByDesign().'
    );
});
