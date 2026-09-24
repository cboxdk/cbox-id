<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Navigation\ConsoleNavigation;
use App\Platform\PlatformAuth;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * EVERY PAGE ON THE RAIL EXPLAINS ITSELF.
 *
 * The "?" beside a page title is the console's explanation layer: one {@see HelpTopic}
 * per concept, written for an administrator who has never used the product. Half the
 * rail shipped without one, and nothing could tell — a page with no `help` prop renders
 * a heading with no "?" beside it, which looks deliberate. So the rule is asserted over
 * what the server sends, page by page, on every console, and a page a module adds later
 * is held to it without that module knowing this test exists.
 *
 * The rail is read the way the shell reads it: a page whose console-kit feature is off is
 * not on the rail and is skipped. Every page that IS on it has to answer 200 with a topic,
 * so a fixture that stopped admitting its pages fails here rather than checking nothing.
 */

/** The platform areas are the operator's, and have a fixture of their own below. */
const HELP_PLATFORM_AREAS = ['platform', 'platform-insights', 'platform-admin'];

/** Every module feature on, so the modules' pages are on the rail and measured too. */
function everyModuleOnForHelp(): void
{
    config([
        'id-analytics.enabled' => true,
        'compliance.enabled' => true,
        'connectors.enabled' => true,
        'id-devices.enabled' => true,
    ]);
}

/**
 * Visit each route and return the ones whose page carries no help topic.
 *
 * @param  list<string>  $routes
 * @return list<string>
 */
function pagesWithoutHelp(array $routes): array
{
    $missing = [];

    foreach ($routes as $route) {
        $response = test()->get(route($route));

        if ($response->status() !== 200) {
            $missing[] = "{$route}: answered {$response->status()}, so its help could not be read";

            continue;
        }

        $help = $response->inertiaProps('help');
        $topic = is_array($help) ? ($help['topic'] ?? null) : null;

        if (! is_string($topic) || $topic === '') {
            $missing[] = "{$route}: no help topic";
        }
    }

    return $missing;
}

/**
 * The routes of the registry areas on the rail right now, gated the way ShellPayload gates
 * them: an inactive feature has no page.
 *
 * @param  callable(string): bool  $includeArea
 * @return list<string>
 */
function registryRailRoutes(callable $includeArea): array
{
    $routes = [];

    foreach (Console::nav()->areas() as $area) {
        if (! $includeArea($area->key)) {
            continue;
        }

        foreach ($area->pages() as $page) {
            if ($page->feature !== null && ! Console::featureActive($page->feature)) {
                continue;
            }

            $routes[] = $page->route;
        }
    }

    return $routes;
}

it('gives every organization console page on the rail a help topic', function (): void {
    everyModuleOnForHelp();

    $subject = app(Subjects::class)->create('help@acme.test', 'Help Admin', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-help'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    // The token vault sits behind the sudo step-up and would otherwise answer a redirect.
    confirmStepUp();

    // The workspace area belongs to a workspace owner at the platform root, and is
    // measured with that fixture in the test below.
    $routes = registryRailRoutes(
        fn (string $key): bool => ! in_array($key, [...HELP_PLATFORM_AREAS, 'identity-platform'], true),
    );

    expect(pagesWithoutHelp($routes))->toBe([])
        // A floor, so a fixture that stopped admitting pages cannot report a clean rail.
        ->and(count($routes))->toBeGreaterThanOrEqual(25);
});

it('gives every workspace page, and both Keys tabs, a help topic', function (): void {
    everyModuleOnForHelp();
    platformRootDeployment();

    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'help-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    signInAsMember($result->owner->id);

    $routes = registryRailRoutes(fn (string $key): bool => $key === 'identity-platform');

    // Projects, Team, Keys, Environment domains, Billing, Workspace settings.
    expect($routes)->toHaveCount(6);

    // The Keys page's second tab is its own URL and not on the rail.
    expect(pagesWithoutHelp([...$routes, 'keys.workspace']))->toBe([]);
});

it('gives every environment console page on the rail a help topic', function (): void {
    everyModuleOnForHelp();
    crudSetup();

    // The token vault and Legacy login sit behind the environment's step-up.
    confirmEnvironmentStepUp();

    $routes = [];

    foreach (app(ConsoleNavigation::class)->environment()->areas as $area) {
        foreach ($area->pages as $page) {
            $routes[] = $page->route;
        }
    }

    // Frontend keys is the Keys page's second tab, reached by its own URL.
    $routes[] = 'environment.keys.frontend';

    expect(pagesWithoutHelp($routes))->toBe([])
        ->and(count($routes))->toBeGreaterThanOrEqual(30);
});

it('gives every platform page on the rail a help topic', function (): void {
    actAsOperator();

    $routes = registryRailRoutes(fn (string $key): bool => in_array($key, HELP_PLATFORM_AREAS, true));

    // Workspaces, Environments, Organizations, Usage, Search, Queues, Operators.
    expect($routes)->toHaveCount(7)
        ->and(pagesWithoutHelp($routes))->toBe([]);
});
