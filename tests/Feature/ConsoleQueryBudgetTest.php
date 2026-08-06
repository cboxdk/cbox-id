<?php

declare(strict_types=1);

use App\Http\Middleware\PointAtFirstRun;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\Install\Contracts\PlatformInstaller;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * A ceiling, not a benchmark. It exists to catch the shape of regression that keeps
 * recurring here: a per-ROW query in a component that renders once per row.
 *
 * The delete-confirmation dialog and its nested environment badge each queried for the
 * current environment independently, so a members page cost two extra queries per member
 * — measured at 37 queries with two members and 83 with twenty-five, exactly linear
 * across 45 call sites. That is why the assertion below scales the roster: a fixed-size
 * fixture would not have noticed.
 *
 * The numbers are deliberately loose. They are not a performance target; they are a trip
 * wire, and a trip wire that fires on ordinary drift gets deleted.
 */
/** @return array{0: string} the organization id */
function queryBudgetAdmin(int $extraMembers = 0): array
{
    $subject = app(Subjects::class)->create('budget@acme.test', 'Budget Admin', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-budget'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    for ($i = 0; $i < $extraMembers; $i++) {
        $extra = app(Subjects::class)->create("member{$i}@acme.test", "Member {$i}", 'super-secret-1234');
        app(Memberships::class)->add($org->id, $extra->id, MembershipRole::Member);
    }

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return [$org->id];
}

function addMembers(string $organizationId, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $extra = app(Subjects::class)->create("extra{$i}@acme.test", "Extra {$i}", 'super-secret-1234');
        app(Memberships::class)->add($organizationId, $extra->id, MembershipRole::Member);
    }
}

/** @return int the number of queries the request issued */
function queriesFor(string $route): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->get(route($route))->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('does not pay a per-row query on the member roster', function (): void {
    [$orgId] = queryBudgetAdmin(extraMembers: 2);
    $small = queriesFor('members');

    // The SAME application and the same page, with eighteen more rows in it. Measuring
    // in one instance on purpose: refreshApplication() would drop the in-memory schema.
    addMembers($orgId, 18);
    $large = queriesFor('members');

    fwrite(STDERR, "\n  members: {$small} queries at 3 rows, {$large} at 21\n");

    // Ten times the rows must not cost ten times the queries. The delete dialog and its
    // nested badge added two per row, so this gap was ~36; a batched page is flat.
    expect($large - $small)->toBeLessThan(
        10,
        "the roster costs per-row queries: {$small} at 3 rows, {$large} at 21"
    );
});

/**
 * Ceilings taken from a real measurement, then given room: dashboard 61, settings 17 at
 * the time of writing. They are trip wires for a doubling, not performance targets — a
 * wire that fires on ordinary drift gets deleted rather than heeded.
 *
 * The dashboard's number is high for a reason worth knowing: every Volt `with()` runs
 * TWICE per render, because Volt calls it and Livewire's own SupportWithMethod hook
 * calls it again. That is upstream, not ours, and halving it needs either a memo in
 * every component or a change in one of the two packages.
 */
it('keeps the most-loaded console pages within budget', function (string $route, int $ceiling): void {
    queryBudgetAdmin();

    $count = queriesFor($route);

    expect($count)->toBeLessThan($ceiling, "{$route} now costs {$count} queries (ceiling {$ceiling})");
})->with([
    'dashboard' => ['dashboard', 75],
    'settings' => ['settings', 30],
]);

/**
 * The cost of a page must not scale with how many organizations the signed-in subject
 * belongs to.
 *
 * Two framework defects compounded here, both on the authentication path — which is also
 * persistent Livewire middleware, so it ran again on every round trip. `memberships` had
 * no index on `user_id` and `forUser()` runs unscoped, so the query scanned every
 * membership belonging to every tenant on the platform; and inside the loop over what it
 * returned, `overrideFor()` was the one policy read left unmemoised. Measured before the
 * fix: 17 queries at one organization, 22 at four, 32 at nine — exactly two per
 * organization.
 *
 * Asserting the SHAPE rather than a ceiling. A ceiling is a number that drifts; "adding
 * eight memberships does not add sixteen queries" is the property.
 */
it('does not pay per-organization queries for the signed-in subject', function (): void {
    [$orgId] = queryBudgetAdmin();
    $subject = app(CurrentUser::class)->subject();

    $small = queriesFor('settings');

    // The SAME subject, now a member of eight more organizations. One application
    // instance on purpose: refreshApplication() would drop the in-memory schema.
    for ($i = 0; $i < 8; $i++) {
        $extra = app(Organizations::class)->create(new NewOrganization("Extra {$i}", "extra-budget-{$i}"));
        app(Memberships::class)->add($extra->id, $subject->id, MembershipRole::Member);
    }

    $large = queriesFor('settings');

    fwrite(STDERR, "\n  settings: {$small} queries at 1 org, {$large} at 9\n");

    // Flat, not merely bounded. Eight more memberships must not add eight more queries,
    // and after the batch read they add none at all — measured 16 at one organization
    // and 15 at nine, against 17 and 32 before. The small allowance is for ordinary
    // drift, not for a per-row cost.
    expect($large - $small)->toBeLessThan(
        3,
        "the page costs per-organization queries: {$small} at 1 org, {$large} at 9"
    );
});

/*
|--------------------------------------------------------------------------
| The ENVIRONMENT plane
|--------------------------------------------------------------------------
| Everything above measures the organization console. The environment console is
| the one an account's administrator lives in, it is where the acting-organization
| switcher lives, and it was where both of the worst measurements were — because
| nothing here reached it.
*/

/**
 * Measure a request the way a reviewer with `DB::listen` would: every statement, in
 * order, so the SHAPE can be asserted and not just the total.
 *
 * `nextRequest()` first, always. ConsoleScope, CurrentUser and the environment-admin
 * resolver are all bound `scoped` and all memoise; a real deployment drops those between
 * requests and Laravel's HTTP test helpers do not, so without it the second measurement
 * in a test reads the FIRST one's memos and every invariance assertion passes for free.
 *
 * @return array{queries: list<string>, html: int}
 */
function consoleRequest(string $route): array
{
    nextRequest();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = test()->get(route($route));
    $response->assertOk();

    return ['queries' => $queries, 'html' => strlen((string) $response->getContent())];
}

/** An environment administrator, acting on one of this environment's organizations. */
function environmentBudgetAdmin(int $organizations = 1): string
{
    ['envId' => $envId] = crudSetup();

    $first = null;

    for ($i = 0; $i < $organizations; $i++) {
        $organization = app(Organizations::class)->create(new NewOrganization("Budget Org {$i}", "budget-org-{$i}"));
        $first ??= $organization->id;
    }

    // Choosing is the steady state an administrator is in, and it is the state the cost
    // lived in: with nothing chosen, organizationId() returns before it validates
    // anything, so a fixture that never chose would have measured a page nobody uses.
    if ($first !== null) {
        app(ConsoleScope::class)->chooseOrganization($first);
    }

    return $envId;
}

/**
 * The finding this exists for: the acting-organization picker enumerated every
 * organization in the environment into the page chrome, each as its own `<form>` with its
 * own CSRF token, on EVERY environment-console page.
 *
 * At the seven organizations a developer has locally that is invisible. At five hundred it
 * is a 400 KB document; at five thousand it was 3.5 MB, before anybody clicked anything.
 * That is an availability problem rather than a slow page, and no ceiling on a query count
 * would ever have caught it — which is why this asserts the DOCUMENT as well.
 *
 * Both halves are the property: the cost of a page must not scale with the size of the
 * tenant, in queries OR in bytes.
 */
it('does not pay for the size of the environment when rendering an environment-console page', function (): void {
    environmentBudgetAdmin(organizations: 7);

    $small = consoleRequest('environment.roles');

    // The SAME application, five hundred more organizations in it. One instance on
    // purpose: refreshApplication() would drop the in-memory schema.
    for ($i = 0; $i < 500; $i++) {
        app(Organizations::class)->create(new NewOrganization("Scale Org {$i}", "scale-org-{$i}"));
    }

    $large = consoleRequest('environment.roles');

    $smallQueries = count($small['queries']);
    $largeQueries = count($large['queries']);
    $growthKb = round(($large['html'] - $small['html']) / 1024, 1);

    fwrite(STDERR, "\n  environment.roles: {$smallQueries} queries / ".round($small['html'] / 1024, 1)
        ." KB at 7 orgs, {$largeQueries} / ".round($large['html'] / 1024, 1)." KB at 507\n");

    expect($largeQueries)->toBeLessThanOrEqual(
        $smallQueries,
        "the page costs queries per organization in the environment: {$smallQueries} at 7, {$largeQueries} at 507"
    );

    // Five hundred organizations may add a count and a few characters to "Showing 8 of
    // 507" — nothing more. It was +339 KB.
    expect($growthKb)->toBeLessThan(
        2.0,
        "the page grows with the size of the environment: +{$growthKb} KB for 500 more organizations"
    );
});

/**
 * A trip wire on REPETITION rather than on a total, because a total is a number that
 * drifts and "the console asked the same question four times" is a defect however many
 * queries the page happens to have.
 *
 * The threshold is two PER RENDER, and a console page renders twice: Volt calls the
 * component's `with()` and Livewire's own SupportWithMethod hook calls it again. That is
 * upstream and is documented at the top of this file. So four is the wire — anything
 * repeated more often is being asked twice inside a single render, which is the shape
 * every finding on this page turned out to be: the same user row read twice, the same
 * platform-root row resolved five times, the same organization list ten times.
 */
it('does not ask the same question twice within a console render', function (): void {
    environmentBudgetAdmin(organizations: 7);

    foreach (['environment.roles', 'environment.connections', 'environment.directories'] as $route) {
        $counts = array_count_values(consoleRequest($route)['queries']);
        arsort($counts);

        $worst = (string) array_key_first($counts);
        $times = $counts[$worst] ?? 0;

        expect($times)->toBeLessThanOrEqual(
            4,
            "{$route} runs one statement {$times} times (two renders × two is the ceiling): {$worst}"
        );
    }
});

/**
 * The same wire on the ORGANIZATION plane, which is the one an end customer's admin uses.
 *
 * `/connections` is the page the entitlement reader is hottest on: eight `entitled()`
 * calls, each two cache round trips, and on the default `database` cache store every one
 * of those is a query — sixteen of the page's thirty-five.
 */
it('does not ask the same question twice within an organization-console render', function (): void {
    installedDeployment();
    queryBudgetAdmin();

    $counts = array_count_values(consoleRequest('connections')['queries']);
    arsort($counts);

    $worst = (string) array_key_first($counts);
    $times = $counts[$worst] ?? 0;

    expect($times)->toBeLessThanOrEqual(
        4,
        "connections runs one statement {$times} times (two renders × two is the ceiling): {$worst}"
    );
});

/**
 * "Is this deployment still unclaimed?" is asked twice on a Livewire round trip, because
 * {@see PointAtFirstRun} is registered twice on purpose — in the
 * global `web` group AND in Livewire's persistent list, which is where a gate that must
 * survive a component action is STATED rather than inferred from grouping.
 *
 * Both registrations are right, so the fix is that the second ask is free. Asserted on the
 * installer directly rather than through a real POST to /livewire/update: what the double
 * registration causes is two calls to this one scoped object inside one request, and that
 * is exactly what this reproduces — without a hand-built Livewire snapshot whose checksum
 * would break on a framework upgrade and tell us nothing about the console.
 */
it('answers the first-run question once per request however many gates ask it', function (): void {
    installedDeployment();

    $installer = app(PlatformInstaller::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $first = $installer->isEmpty();
    $firstCost = count(DB::getQueryLog());

    DB::flushQueryLog();
    $second = $installer->isEmpty();
    $secondCost = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The first ask walks the occupant kinds until it finds one, so its cost depends on
    // what this deployment happens to hold — which is exactly why the assertion is about
    // the SECOND ask. Whatever the question costs, it is asked once.
    expect($first)->toBeFalse()
        ->and($second)->toBeFalse()
        ->and($firstCost)->toBeGreaterThan(0)
        ->and($secondCost)->toBe(0, "the first-run gate re-queried on the second ask ({$secondCost} queries)");

    // …and the memo is bounded by the request, not by the process. The binding is
    // `scoped`, so a long-lived worker must not carry "this platform is empty" across the
    // request that stopped it being true.
    nextRequest();

    expect(app(PlatformInstaller::class))->not->toBe($installer);
});
