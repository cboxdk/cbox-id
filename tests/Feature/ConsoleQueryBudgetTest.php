<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
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
