<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * AUTHORIZATION THAT EXPIRES WHEN ACCESS DOES, NOT WHEN THE USER NAVIGATES.
 *
 * These pages asked their capability question in mount(), and Livewire runs mount() ONCE.
 * A page already open re-hydrates from its snapshot and goes straight to render()/with(),
 * so somebody downgraded out of the capability kept a working page: their browser went on
 * posting to /livewire/update and going on receiving the roster, the environment keys, the
 * API keys, the invoices, for as long as the tab stayed open.
 *
 * THE PORTED PAGES NO LONGER HAVE A SNAPSHOT. Every interaction is a request through the
 * console middleware, so the question is asked on each one by construction — which is why
 * the two halves of this file look nothing alike. What was a source-shape test for a
 * hazard that could not be exercised is, for a controller, the ordinary behavioural test
 * that hazard always deserved: reach the page, lose the capability, ask again.
 */

/**
 * An ADMIN of a provisioned account, signed in, ready to be downgraded.
 *
 * Not the owner: `Memberships` refuses to demote the last one, and rightly — an
 * organization with no owner has nobody who can hand it back. The capability being taken
 * away is the same either way, and an admin losing it is the case that actually happens.
 *
 * @return array{0: string, 1: string} [organization id, subject id]
 */
function anAdminToDowngrade(): array
{
    ['organization' => $organization] = provisionAccount();

    [, $subjectId] = memberWithRole($organization->id, MembershipRole::Admin, 'admin@acme.example');

    signInAsMember($subjectId);

    return [$organization->id, $subjectId];
}

/** Take a capability away from the person currently holding the page open. */
function downgradeTo(string $organizationId, string $subjectId, MembershipRole $role): void
{
    app(PlatformRoot::class)->run(
        fn () => app(Memberships::class)->changeRole($organizationId, $subjectId, $role),
    );
}

it('stops serving a ported console page the request after the capability is taken away', function (string $route, MembershipRole $downgrade, ?string $redirectsTo): void {
    [$organizationId, $subjectId] = anAdminToDowngrade();

    // It is theirs to begin with — otherwise the refusal below proves nothing about the
    // downgrade and everything about the fixture.
    test()->get(route($route))->assertOk();

    downgradeTo($organizationId, $subjectId, $downgrade);

    // THE VERY NEXT REQUEST, with no sign-out and no navigation in between. Under Volt
    // this was the request that kept working.
    $response = test()->get(route($route));

    /*
     * HOW it refuses is the row's own business, and the row states it rather than the
     * assertion accepting either. Two shapes are right for different pages: somewhere they
     * can still be is kinder for a page reached from the nav they were just looking at,
     * and a flat 403 is the honest answer where there is nothing to send them to. What
     * must never pass is a redirect to sign-in — that reads as "your session ended" for
     * somebody whose session is fine — so the destination is named, never just asserted to
     * exist.
     */
    $redirectsTo === null
        ? $response->assertForbidden()
        : $response->assertRedirect(route($redirectsTo));
})->with([
    // A Developer may not read the roster (it is PII) and may not manage API keys.
    'the roster, which is PII' => ['members', MembershipRole::Developer, 'projects'],
    'the organization API keys' => ['api-keys', MembershipRole::Developer, 'projects'],
    // Roles are the access itself: who may act as what inside the apps. A Developer may
    // read an app's configuration and may not decide that.
    'the role catalogue' => ['roles', MembershipRole::Developer, null],
])->group('security');

it('refuses a WRITE the request after the capability is taken away', function (): void {
    [$organizationId, $subjectId] = anAdminToDowngrade();

    [$target] = memberWithRole($organizationId, MembershipRole::Member, 'target@acme.example');

    downgradeTo($organizationId, $subjectId, MembershipRole::Developer);

    /*
     * The half a page-load guard could never cover. `manageableTarget()` answers null for
     * somebody without the manage capability, so the write is a no-op — and the row is
     * what says so, not the status code: the console answers a refused member action with
     * a redirect back to a page they can still see, deliberately, because a 404 there
     * would deny the existence of a row rendered three lines above it.
     */
    test()->from(route('members'))->delete(route('members.remove', $target->id));

    expect(freshMembership($target))->not->toBeNull();
})->group('security');

/*
 * THE VOLT SHAPE SWEEP IS GONE, and its own docblock said when: "each entry goes when that
 * page is ported".
 *
 * It read a component's `boot()` for a capability check, because the hazard was Livewire's
 * `/livewire/update` seam — a page already open re-hydrated from its snapshot and called
 * `render()` straight through, so somebody downgraded out of a capability kept a working
 * tab. `mount()` runs once; only `boot()` re-ran.
 *
 * Billing was the last entry, and it is a controller now: there is no second path into it
 * to forget about, so the behavioural rows above — which drive the real endpoint and check
 * what changed — are strictly stronger than any shape row could be.
 */
