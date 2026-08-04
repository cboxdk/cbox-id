<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Every module console page is administration, and must refuse a plain member.
 *
 * The module routes carry `platform.auth` (a session exists) and `console.feature` (the
 * flag is on). NEITHER is a role check — `RequireFeature` 404s an inactive feature and
 * does nothing else. The only thing that hid these pages from a member was the nav
 * dropping the area from the sidebar, which is styling: the URL is typeable.
 *
 * Six of the seven pages shipped with no gate at all. The worst were the two always-on
 * modules: whitelabel let any member rewrite the environment's branding and set or clear
 * the custom domain that host→environment resolution keys on, and risk events listed
 * every flagged sign-in in the environment with the address in the clear. Compliance —
 * off by default, so exposed only where it is switched on — let any member run an audit
 * export, apply a retention purge that DELETES audit rows, and read any subject's
 * data-subject bundle from a free-text field.
 *
 * Driven from the nav registry rather than a hand-written list, so a module added later
 * is covered without anyone remembering this file.
 */
function moduleConsoleMember(MembershipRole $role = MembershipRole::Member): void
{
    // Fresh identity per call: each route is checked with its own session, so the
    // previous route's failure cannot leave state that admits the next one.
    static $n = 0;
    $n++;

    $subject = app(Subjects::class)->create($role->value.$n.'@acme.test', 'Member', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-module-authz-'.$n));
    app(Memberships::class)->add($org->id, $subject->id, $role);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, $role);
}

/**
 * The module-contributed console pages, from the registry the sidebar renders from.
 *
 * @return list<string>
 */
function moduleConsoleRoutes(): array
{
    $hostRoutes = ['dashboard', 'usage', 'approvals', 'members', 'roles', 'connections',
        'directories', 'provisioning', 'governance', 'sod-policies', 'clients', 'webhooks',
        'hooks', 'vault', 'audit', 'settings', 'appearance', 'account', 'get-started',
        // The Identity platform area — host pages, not module pages, and gated on what
        // the acting ORGANIZATION owns rather than on the membership role this file
        // sweeps for. An org admin who owns no identity providers is correctly refused
        // them, which is the opposite of what "admits an admin" asserts below.
        'projects', 'account-members', 'api-keys', 'environment-keys',
        'environment-domains', 'account-activity', 'billing', 'account-settings'];

    // Personal, not administrative: it lists the caller's OWN handsets and belongs to
    // every signed-in user. Pinned open by its own test below, so removing it here does
    // not quietly drop it from coverage.
    $personal = ['devices.mine'];

    $routes = [];

    foreach (Console::nav()->areas() as $area) {
        foreach ($area->pages() as $page) {
            if (! in_array($page->route, $hostRoutes, true)
                && ! in_array($page->route, $personal, true)
                && Route::has($page->route)) {
                $routes[] = $page->route;
            }
        }
    }

    return $routes;
}

it('refuses every module console page to a plain member', function (): void {
    // Every module feature ON, so `RequireFeature` cannot 404 a page before the
    // authorization guard runs — an inactive feature would mask exactly the hole this
    // test exists to prove is closed.
    config([
        'id-analytics.enabled' => true,
        'compliance.enabled' => true,
        'connectors.enabled' => true,
        'id-devices.enabled' => true,
    ]);

    $routes = moduleConsoleRoutes();

    // Guard against the loop silently checking nothing: the modules are vendored in, so
    // an empty list means the registry stopped reporting them, not that they are safe.
    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        moduleConsoleMember();

        expect($this->get(route($route))->status())
            ->toBe(403, "route [{$route}] must refuse a plain member");
    }
});

it('admits an admin to the same pages', function (): void {
    foreach (moduleConsoleRoutes() as $route) {
        moduleConsoleMember(MembershipRole::Owner);

        // 200, or a redirect a feature/entitlement gate owns — never 403 for an owner.
        expect($this->get(route($route))->status())
            ->not->toBe(403, "route [{$route}] must admit an owner");
    }
});

/**
 * The personal device page is the deliberate exception: it lists the caller's OWN
 * handsets and belongs to every signed-in user. Pinned so a later sweep that "fixes"
 * the inconsistency has to argue with this test first.
 */
it('keeps the personal device page open to a plain member', function (): void {
    config()->set('id-devices.enabled', true);
    moduleConsoleMember();

    expect($this->get(route('devices.mine'))->status())->not->toBe(403);
})->skip(fn (): bool => ! Route::has('devices.mine'), 'devices module not routed');
