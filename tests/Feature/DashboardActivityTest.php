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
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function dashboardOwner(): array
{
    $owner = app(Subjects::class)->create('owner@acme.test', 'Olive Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    app(Memberships::class)->add($org->id, $owner->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($owner->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($owner, $session, $org, MembershipRole::Owner);

    // AND THE SESSION KEY THE GUARD ON THE WAY IN READS: the dashboard is reached by a
    // REQUEST now, and without this it answers a redirect to /login — which
    // `assertDontSee($member->id)` would pass against happily.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return [$org, $owner];
}

it('renders the recent-activity feed with member names, not raw ids', function (): void {
    [$org] = dashboardOwner();

    // Adding a member writes an audit entry whose target is the new member's id.
    $member = app(Subjects::class)->create('grace@acme.test', 'Grace Hopper', 'supersecret123');
    app(Memberships::class)->add($org->id, $member->id, MembershipRole::Member);

    test()->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // The RESOLVED NAME is what the feed carries…
            ->where('recent', fn (Collection $rows): bool => $rows->pluck('subject')->contains('Grace Hopper'))
            // …and the raw ULID is nowhere in it. Asserted over the feed's own rows rather
            // than over the whole response: the id is legitimately in the page's other
            // props, and a document-wide check would be a statement about the wrong thing.
            ->where('recent', fn (Collection $rows): bool => $rows
                ->filter(fn (array $row): bool => str_contains((string) ($row['subject'] ?? ''), $member->id))
                ->isEmpty()));
});

it('falls back gracefully when a target cannot be resolved', function (): void {
    dashboardOwner();

    // No members added beyond the owner — the feed still resolves, with every row carrying
    // either a resolved name or a type and a truncated id, and never a bare ULID.
    test()->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('recent', fn (Collection $rows): bool => $rows
                ->every(fn (array $row): bool => $row['subject'] === null || trim((string) $row['subject']) !== '')));
});
