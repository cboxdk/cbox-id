<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\Navigation\ConsoleNav;
use App\Platform\Navigation\ConsoleNavigation;
use App\Platform\PlatformAuth;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Invariants of the console's information architecture. All three were broken in
 * shipped code, and none is the kind of thing anyone notices reading a diff.
 */
it('gives every nav area a unique order', function (): void {
    // DefaultNavRegistry::areas() sorts on `order` alone, so a tie resolves by service
    // provider boot order: the rail silently reshuffles when a module is enabled or
    // disabled. The console shipped Logs/Security both at 60 and Settings/Connectors
    // both at 70.
    $orders = collect(Console::nav()->areas())->map(fn ($area): int => $area->order);

    expect($orders->duplicates()->values()->all())->toBe([]);
});

it('never puts two rail areas on the same subject', function (): void {
    $keys = collect(Console::nav()->areas())->pluck('key')->all();

    // Analytics belongs beside Overview › Usage, compliance's audit pages beside the
    // activity log, and risk events in Logs — not each in an area of its own. A rail
    // with one area per module asks the reader to learn our module boundaries.
    expect($keys)->not->toContain('analytics')
        ->and($keys)->not->toContain('compliance')
        ->and($keys)->not->toContain('security');
});

/**
 * The promise a nav label makes: click "Token vault" and you land on a page headed
 * "Token vault". Six host pages and two plugin pages broke it. Asserted over the
 * rendered document rather than the source, so it holds for any page a module adds
 * later without that module knowing this test exists.
 */
it('lands every nav entry on a page titled the way the entry is labelled', function (): void {
    $subject = app(Subjects::class)->create('nav@acme.test', 'Nav Admin', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-areas'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    // Behind the sudo step-up gate: it redirects, so there is no title to read.
    $behindStepUp = ['vault'];

    $checked = 0;

    foreach (Console::nav()->areas() as $area) {
        foreach ($area->pages() as $page) {
            if (in_array($page->route, $behindStepUp, true) || ! Route::has($page->route)) {
                continue;
            }

            $response = $this->get(route($page->route));

            // A page gated off in this environment (inactive module, missing
            // entitlement) is not a naming failure — only a reachable page is checked.
            if ($response->status() !== 200) {
                continue;
            }

            expect($response->getContent())->toContain('<title>'.e($page->label).' · ');
            $checked++;
        }
    }

    // Guard against the loop silently checking nothing: a broken session would 302
    // every page and turn this into a test that always passes.
    expect($checked)->toBeGreaterThanOrEqual(12);
});

/**
 * The same promise, on the planes the test above cannot see.
 *
 * `Console::nav()` is the plugin registry, which is the ORGANIZATION plane only. The
 * workspace and platform planes declare themselves in {@see ConsoleNavigation}, so
 * nothing reached them and both had shipped violations: the rail said "Profile" while
 * the tab said "Security" and the heading said "Profile & security"; the rail said
 * "Domains" over a page headed "Environment domains". The organization plane does not
 * have this class of bug precisely because it has had a test since the day it was
 * written, so the guard is the durable half of the fix, not the renames.
 *
 * Three assertions per page, not one: nav label === <title> === <h1>. A page can satisfy
 * any two of those and still tell a person three different things.
 */
function assertNavEntriesMatchTheirPages(ConsoleNav $nav, string $plane): int
{
    $checked = 0;

    foreach ($nav->areas as $area) {
        foreach ($area->pages as $page) {
            if (! Route::has($page->route)) {
                continue;
            }

            $response = test()->get(route($page->route));

            // Gated off in this environment (inactive module, missing entitlement, a
            // shape this deployment does not have) is not a naming failure.
            if ($response->status() !== 200) {
                continue;
            }

            $html = (string) $response->getContent();
            $label = e($page->label);

            // `toContain` is variadic in Pest, so a message passed to it reads as a
            // second needle and the failure names the wrong thing. Assert the boolean.
            expect(str_contains($html, '<title>'.$label.' · '))->toBeTrue(
                "{$plane} › {$area->label} › {$page->label}: the nav entry and the <title> disagree",
            );

            // The h1 carries classes and whitespace, so match on its text rather than on
            // a whole tag: this has to hold for a heading rendered by x-page-header and
            // for one a page still writes itself.
            expect((bool) preg_match('/<h1[^>]*>\s*'.preg_quote($label, '/').'\s*</', $html))->toBeTrue(
                "{$plane} › {$area->label} › {$page->label}: the nav entry and the <h1> disagree",
            );

            $checked++;
        }
    }

    return $checked;
}

it('lands every workspace nav entry on a page titled and headed the way the entry is labelled', function (): void {
    platformRootDeployment();

    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'areas-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    signInAsMember($result->member);

    // At its widest role: the question is "does this page name itself the way the rail
    // names it", not "may an auditor see it".
    $checked = assertNavEntriesMatchTheirPages(
        app(ConsoleNavigation::class)->workspace(AccountRole::Owner),
        'workspace',
    );

    expect($checked)->toBeGreaterThanOrEqual(4);
});

it('lands every environment nav entry on a page titled and headed the way the entry is labelled', function (): void {
    // The environment plane is where the module-declared pages are merged in, so this is
    // also the loop that catches a module naming its page one thing in the rail and
    // another in its own heading — without that module knowing this test exists.
    $setup = crudSetup();

    $checked = assertNavEntriesMatchTheirPages(
        app(ConsoleNavigation::class)->environment(),
        'environment',
    );

    expect($setup['envId'])->not->toBe('')
        ->and($checked)->toBeGreaterThanOrEqual(10);
});

it('lands every platform nav entry on a page titled and headed the way the entry is labelled', function (): void {
    actAsOperator();

    $checked = assertNavEntriesMatchTheirPages(
        app(ConsoleNavigation::class)->operator(),
        'platform',
    );

    // Seven pages across three areas; anything less means the session stopped resolving
    // and the loop is asserting nothing.
    expect($checked)->toBe(7);
});
