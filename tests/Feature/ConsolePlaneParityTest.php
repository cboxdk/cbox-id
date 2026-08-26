<?php

declare(strict_types=1);

use App\Platform\OperatorEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * A CAPABILITY BELONGS TO BOTH CONSOLE PLANES UNLESS SOMEBODY SAID OTHERWISE.
 *
 * The console was built twice — an organization plane and an environment plane, each with
 * its own shell and its own hand-written rail — and pages drifted between them silently.
 * Two found by inventory:
 *
 *  - `permissions` was environment-plane-only, so an organization administrator could
 *    assign roles while unable to see the permissions those roles are made of.
 *  - `audit-streams` (Log streaming) was environment-plane-only, though shipping an audit
 *    trail to a SIEM is an obligation the ORGANIZATION carries — the party answerable for
 *    it could not see whether it ran.
 *
 * Both were one component already; what made them single-plane was a hardcoded
 * `layouts.environment` and an `EnvironmentAdminAuth` gate that is null on any other
 * plane, so the page answered 403 rather than being absent — the worse failure, because
 * it looks like a permissions problem.
 *
 * Route existence AND admission are both asserted: a route that exists and 403s for the
 * person who owns the thing is not parity.
 */
$shared = [
    'permissions' => 'environment.permissions',
    'audit-streams' => 'environment.audit-streams',
];

it('routes each shared capability on both planes', function () use ($shared): void {
    foreach ($shared as $org => $env) {
        expect(Route::has($org))->toBeTrue("[{$org}] must exist on the organization plane")
            ->and(Route::has($env))->toBeTrue("[{$env}] must exist on the environment plane");
    }
});

it('renders a shared capability from one component, not two', function () use ($shared): void {
    foreach ($shared as $org => $env) {
        $orgAction = Route::getRoutes()->getByName($org)?->getAction();
        $envAction = Route::getRoutes()->getByName($env)?->getAction();

        // The same Volt component behind both names. Two components rendering "the same"
        // page is exactly how the labels, the columns and the empty states drifted apart.
        expect($orgAction['view'] ?? $orgAction['component'] ?? null)
            ->toBe($envAction['view'] ?? $envAction['component'] ?? null, "[{$org}] and [{$env}] must be one component");
    }
});

it('admits the organization owner to every shared capability', function () use ($shared): void {
    actingAsRole(MembershipRole::Owner);

    foreach (array_keys($shared) as $org) {
        // Not 403. The gate that made these environment-only was an env-admin identity
        // asked outright, which is null on this plane — so the page existed and refused.
        expect($this->get(route($org))->status())
            ->not->toBe(403, "route [{$org}] must admit an organization owner");
    }
});

/**
 * THE TARGET SWITCHER MUST OFFER THE THING ITS LABEL LOOKS LIKE.
 *
 * Selecting a row re-points the operator console at that environment while staying on the
 * operator host — the platform pages read through the pointed environment, so that
 * operation has to exist. But "switch target" most naturally reads as "take me to that
 * environment", and that path existed only from Projects: `environment.open` mints a
 * signed handoff to the environment's own host, no second login.
 *
 * So the control that read like navigation could not navigate, and the one that could was
 * on another page. Both are in the menu now.
 */
it('offers both re-pointing and opening from the operator target switcher', function (): void {
    actAsOperator('switcher@platform.test');
    platformRootEnvironment();

    // The switcher only renders with somewhere to switch TO — `$canSwitchEnv` is
    // `count() > 1`, which is right: a menu with one entry and no destination is noise.
    app(EnvironmentContext::class)->withoutScope(
        fn () => Environment::query()->create([
            'name' => 'Production', 'slug' => 'a-tenant-env',
        ]),
    );

    $options = collect(
        (array) $this->get(route('platform.environments'))->assertOk()->inertiaProps('shell.environments')
    );

    // ASKED OF THE PROPS, not of the document: the switcher is a React menu, so the
    // re-point URL is built on the client and never appears in the HTML — a string match
    // on the page would now pass or fail for reasons that have nothing to do with what
    // the menu offers.
    $other = $options->firstWhere('current', false);

    expect($other)->not->toBeNull('the switcher had nothing to switch to')
        // The handoff link — the half that was missing. A signed hand-off to the
        // environment's own host, so "switch target" can actually take you there.
        ->and($other['openHref'])->toMatch('#/open/[0-9a-zA-Z]+#');

    // …and re-pointing still works, driven rather than matched: the operator-only target
    // key moves to the chosen plane. That is the other half of the menu.
    targetPlatformEnvironment($other['id']);

    expect(session(OperatorEnvironment::SESSION_KEY))->toBe('a-tenant-env');
})->group('ux');
