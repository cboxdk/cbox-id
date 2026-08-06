<?php

declare(strict_types=1);

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateOrganizationApi;
use App\Http\Middleware\AuthenticateEnvironmentAdmin;
use App\Http\Middleware\AuthenticateEnvironmentApi;
use App\Http\Middleware\AuthenticateOperator;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Http\Middleware\EnforceImpersonationWindow;
use App\Http\Middleware\EnforcePlane;
use App\Http\Middleware\PortalSession;
use App\Http\Middleware\RequireMultiTenant;
use App\Http\Middleware\RequireScope;
use App\Http\Middleware\RequireSudo;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetEnvironment;
use App\Platform\EnvironmentAdminAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

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
        AuthenticateOrganizationApi::class => 'API routes only',
        AuthenticateEnvironmentApi::class => 'API routes only',
        RequireScope::class => 'API routes only',
    ];
}

it('re-runs every console auth guard on a Livewire action', function (): void {
    // These were missing, which left the environment-admin console, the plane bulkheads,
    // the sudo step-up and the impersonation block unenforced on /livewire/update.
    // BlockDuringImpersonation was found by the invariant test below, not by inspection —
    // which is the reason that test exists.
    //
    // The account plane's own gate was on this list too. It is not missing: the pages it
    // guarded are console pages now, behind the subject session `Authenticate` already
    // re-runs here, and a gate with nothing left to gate was deleted rather than kept
    // pointing at nothing.
    expect(persistentMiddleware())
        ->toContain(AuthenticateEnvironmentAdmin::class)
        ->toContain(EnforcePlane::class)
        // The sixth, found the same way BlockDuringImpersonation was — by the invariant
        // test below rather than by inspection. `multi.tenant` decides whether the whole
        // environment console exists on this deployment; unregistered, that decision held
        // on the page load and stopped holding on every action the page then performed.
        ->toContain(RequireMultiTenant::class)
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

/**
 * The second layer, tested WITHOUT the first.
 *
 * The persistent-middleware fix above closes the hole, but it is one line in one
 * provider — and its absence is exactly what made the environment console answer
 * unauthenticated action requests. So every environment/* component now also asserts an
 * env-admin session in boot() (boot, not mount: only boot re-runs per action).
 *
 * This test proves that layer stands on its own, by driving a component directly through
 * Volt::test — which does not run route middleware at all, and is therefore a faithful
 * simulation of the middleware being missing.
 */
it('refuses an environment console action even with no route middleware at all', function (): void {
    // No env-admin session established.
    expect(app(EnvironmentAdminAuth::class)->check())->toBeFalse();

    // Livewire converts the abort into a RESPONSE rather than letting it propagate, so
    // assert the status — expecting a thrown HttpException here silently passes.
    Volt::test('console.audit')->assertForbidden();
});

/**
 * What `boot()` reaches: its own body, plus the body of any `$this->method()` it calls.
 *
 * ONE LEVEL OF INDIRECTION, deliberately. `console/connections/index` writes
 * `boot() { $this->authorizeAdmin(); }` with the real check in that private method, and
 * that is the BETTER shape — the guard is named once and reused by the mutating actions
 * beside it. A check that only read `boot()`'s literal body called it unguarded, which
 * would have taught the next author to inline the call to satisfy a test.
 *
 * Not recursive, and that is the honest limit: a guard two hops away is not found, and
 * this test would report it as missing rather than silently pass. Failing towards "look
 * again" is the right direction for a sweep whose whole job is to notice an absence.
 */
function bootReach(string $source): ?string
{
    $body = bootBody($source);

    if ($body === null) {
        return null;
    }

    preg_match_all('/\$this->([A-Za-z_]\w*)\(/', $body, $calls);

    foreach (array_unique($calls[1] ?? []) as $method) {
        $body .= methodBody($source, (string) $method) ?? '';
    }

    return $body;
}

/**
 * The body of a component's `boot()`, or null when it has none.
 *
 * Brace-matched rather than regex'd: a guard is frequently followed by other statements
 * and by nested closures, and a lazy `.*?` to the first `}` would cut the body short and
 * miss a guard that is not on the first line.
 */
function bootBody(string $source): ?string
{
    return methodBody($source, 'boot');
}

/** The brace-matched body of one named method. */
function methodBody(string $source, string $name): ?string
{
    $start = preg_match('/function\s+'.preg_quote($name, '/').'\s*\(/', $source, $m, PREG_OFFSET_CAPTURE) === 1
        ? $m[0][1]
        : false;

    if ($start === false) {
        return null;
    }

    $open = strpos($source, '{', $start);

    if ($open === false) {
        return null;
    }

    $depth = 0;

    for ($i = $open, $len = strlen($source); $i < $len; $i++) {
        $depth += match ($source[$i]) {
            '{' => 1,
            '}' => -1,
            default => 0,
        };

        if ($depth === 0) {
            return substr($source, $open + 1, $i - $open - 1);
        }
    }

    return null;
}

it('guards every environment console component, so a new one cannot skip it', function (): void {
    // array_merge, NOT `+` — see PasswordScreeningTest. With `+` this checked 43 of 49
    // components, skipping all three environment/clients/* (which reveal client secrets).
    $components = array_merge(
        glob(resource_path('views/livewire/environment/*.blade.php')) ?: [],
        glob(resource_path('views/livewire/environment/*/*.blade.php')) ?: [],
        // A merged capability serves the environment plane from `livewire/console` and
        // is reached through exactly the same routes, so a sweep that only looked at
        // `livewire/environment` would stop asking this of a page the moment it was
        // unified — dropping the guard check precisely where the guard was rewritten.
        glob(resource_path('views/livewire/console/*/*.blade.php')) ?: [],
    );

    $unguarded = [];

    foreach ($components as $file) {
        $source = file_get_contents($file) ?: '';

        // MATCHED INSIDE boot(), not anywhere in the file — this is the whole difference
        // between a guard and an import.
        //
        // This used to ask `str_contains($source, 'EnvironmentAdminAuth')` over the whole
        // source, and `use App\\Platform\\EnvironmentAdminAuth;` satisfies that on its own.
        // Emptying `boot()` in a real component while leaving the import in place kept 656
        // tests green — so the sweep that guards 49 console components against
        // unauthenticated Livewire actions was passing on the strength of a `use` line.
        //
        // Its two siblings had already learned this and were not consulted:
        // `PasswordScreeningTest` records that a bare-name check "matched the `use`
        // import… a vacuous test that proved nothing", and `EnvironmentOwnedModelTest`
        // anchors its match with `preg_match('/^\s+use BelongsToEnvironment;/m')` for
        // exactly this reason. The fix here is the same idea: isolate the body of `boot()`
        // and require the call to appear IN it.
        //
        // Any of the three names is the same answer, because all three are ConsoleScope
        // asking "may the person acting on this request change this".
        $body = bootReach($source);

        if ($body === null
            || (! str_contains($body, 'EnvironmentAdminAuth')
                && ! str_contains($body, 'assertMayAdminister')
                && ! str_contains($body, 'accountRole()'))) {
            $unguarded[] = str_replace(resource_path('views/livewire/'), '', $file);
        }
    }

    // A FLOOR, so a moved view directory cannot empty the sweep and report success —
    // the sibling model test carries one for the same reason.
    expect(count($components))->toBeGreaterThan(40, 'the component sweep found almost nothing; did the view directories move?');

    expect($unguarded)->toBe(
        [],
        'These environment console components have no in-component env-admin guard: '
        .implode(', ', $unguarded)
    );
});
