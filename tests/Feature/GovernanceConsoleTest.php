<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\AccessKind;
use Cbox\Id\Governance\Enums\CampaignStatus;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function govAdmin(MembershipRole $role = MembershipRole::Owner): string
{
    $subject = app(Subjects::class)->create('gov@acme.test', 'Gov Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-gov'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    // AND THE SESSION KEY THE CONSOLE'S GUARD READS ON THE WAY IN — without it every
    // request below answers a redirect to /login rather than the page under test.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $org->id;
}

it('opens a review that snapshots access, and applies a revoke on close', function (): void {
    $orgId = govAdmin();
    $role = app(Roles::class)->define($orgId, 'engineer');
    app(Roles::class)->assign($orgId, 'engineer-1', $role->id);

    // Open a review from the console. One component now serves both planes, and the
    // routable index/new/show shape won over the single page — a campaign URL is
    // something you send to a reviewer.
    openAccessReview()->assertSessionHasNoErrors();

    $campaign = CertificationCampaign::query()->where('organization_id', $orgId)->firstOrFail();
    $items = app(AccessReviews::class)->itemsFor($campaign->id);
    // One role assignment (engineer-1) + the admin's own membership.
    expect($items)->toHaveCount(2);

    $roleItem = collect($items)->firstWhere(fn ($i) => $i->access_type === AccessKind::Role);

    // Revoke the role item, then close — the underlying role assignment is removed.
    decideAccessItem($campaign->id, $roleItem->id, 'revoked')->assertSessionHasNoErrors();

    // RECORDED, NOT APPLIED — that is the whole shape of a review, and a test that only
    // checked the end state could not tell the two apart.
    expect(app(Roles::class)->assignmentsForSubject($orgId, 'engineer-1'))->not->toBe([]);

    test()->from(route('governance.show', $campaign->id))
        ->post(route('governance.close', $campaign->id))
        ->assertSessionHasNoErrors();

    expect(app(Roles::class)->assignmentsForSubject($orgId, 'engineer-1'))->toBe([]);
});

it('forbids a non-admin member', function (): void {
    govAdmin(MembershipRole::Member);

    test()->get(route('governance'))->assertForbidden();
});

/**
 * THE REVIEW PAGE READ THE ENTIRE SNAPSHOT AND ASKED FOR EACH PERSON SEPARATELY.
 *
 * A campaign holds one item per role assignment PLUS one per membership in the
 * organization — a set that grows with the customer's end-user count. That whole set was
 * hydrated in `with()`, which Livewire re-runs after every single certify and revoke, and
 * the subject labels beside it were one `find()` per distinct person. A reviewer working
 * through a twenty-thousand-person organization paid for the whole roster again on each
 * decision they made.
 */
it('reviews a page of a campaign at a time, at a flat query cost', function (): void {
    $orgId = govAdmin();
    $role = app(Roles::class)->define($orgId, 'engineer');

    // A SMALL CAMPAIGN AND A LARGE ONE, in the same organization and the same request
    // shape. What this guards is that the page's cost does not scale with the size of the
    // snapshot — an absolute ceiling would be a number about the middleware stack, which
    // has nothing to do with the defect and moves every time the console gains a banner.
    $small = openReviewOver($orgId, $role->id, people: 3, name: 'Small review');
    $large = openReviewOver($orgId, $role->id, people: 40, name: 'Big review');

    $cost = function (string $campaignId): int {
        // Warm first: a cold render pays for schema and config reads that have nothing to
        // do with this page, and comparing a cold render against a warm one measures the
        // wrong thing.
        test()->get(route('governance.show', $campaignId))->assertOk();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        test()->get(route('governance.show', $campaignId))->assertOk();

        return $queries;
    };

    $smallCost = $cost($small);
    $largeCost = $cost($large);

    $page = test()->get(route('governance.show', $large))->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = (array) $page->inertiaProps('items');
    $names = array_column($rows, 'subject');

    fwrite(STDERR, "\n  access review: {$smallCost} queries at 4 items, {$largeCost} at 41\n");

    /*
     * ROWS FIRST, THEN QUERIES — the trap the member roster fell into for months. A page
     * that renders nothing is always cheap.
     *
     * Twenty-five of forty-one on screen: the snapshot is ordered by id, so the first page
     * holds the earliest-created assignments and the fortieth is not among them.
     */
    expect($rows)->toHaveCount(25)
        ->and($names)->toContain('Reviewee 1')
        ->and($names)->not->toContain('Reviewee 40')
        ->and($largeCost - $smallCost)->toBeLessThan(
            3,
            "the review page costs queries per item: {$smallCost} at 4 items, {$largeCost} at 41",
        );
});

/**
 * A campaign over `people` holders of one role, plus the admin's own membership.
 *
 * @return string the campaign id
 */
function openReviewOver(string $organizationId, string $roleId, int $people, string $name): string
{
    foreach (range(1, $people) as $i) {
        $subject = app(Subjects::class)->create(
            Str::slug($name)."-reviewee-{$i}@acme.test",
            "Reviewee {$i}",
        );
        app(Roles::class)->assign($organizationId, $subject->id, $roleId);
    }

    openAccessReview(['name' => $name])->assertSessionHasNoErrors();

    return (string) CertificationCampaign::query()
        ->where('organization_id', $organizationId)
        ->where('name', $name)
        ->value('id');
}

/**
 * Audit finding #24. A review is bulk revocation, and the framework applies it through the
 * bare access contracts — none of the roster's rules. So an administrator who is not an
 * owner, refused when removing an owner directly, could open a review of their own
 * organization, leave the owners undecided under the default "revoke" policy, close it,
 * and every owner but the last was gone.
 */
it('never lets an administrator remove an owner by closing a review', function (): void {
    [$adminId, $org] = actingAsRole(MembershipRole::Admin);
    $ownerA = app(Subjects::class)->create('owner-a@acme.test', 'Owner A', 'supersecret123');
    $ownerB = app(Subjects::class)->create('owner-b@acme.test', 'Owner B', 'supersecret123');
    app(Memberships::class)->add($org->id, $ownerA->id, MembershipRole::Owner);
    app(Memberships::class)->add($org->id, $ownerB->id, MembershipRole::Owner);

    openAccessReview()->assertSessionHasNoErrors();
    $campaign = CertificationCampaign::query()->where('organization_id', $org->id)->firstOrFail();
    $items = collect(app(AccessReviews::class)->itemsFor($campaign->id));
    $ownerItem = $items->firstWhere('subject_id', $ownerA->id);

    // An explicit revoke of an owner is refused where it is made…
    decideAccessItem($campaign->id, $ownerItem->id, 'revoked')->assertSessionHasErrors();

    // …and so is a close that would revoke owners left undecided.
    test()->from(route('governance.show', $campaign->id))
        ->post(route('governance.close', $campaign->id))
        ->assertSessionHasErrors();

    expect(app(Memberships::class)->of($org->id, $ownerA->id)?->role)->toBe(MembershipRole::Owner)
        ->and(app(Memberships::class)->of($org->id, $ownerB->id)?->role)->toBe(MembershipRole::Owner)
        ->and(CertificationCampaign::query()->whereKey($campaign->id)->value('status'))->toBe(CampaignStatus::Open);

    // Certifying what is out of reach is how the administrator closes it; the rest applies.
    foreach ($items as $item) {
        if ($item->subject_id !== $adminId) {
            decideAccessItem($campaign->id, $item->id, 'certified')->assertSessionHasNoErrors();
        }
    }

    test()->from(route('governance.show', $campaign->id))
        ->post(route('governance.close', $campaign->id))
        ->assertSessionHasNoErrors();

    expect(app(Memberships::class)->of($org->id, $ownerA->id)?->role)->toBe(MembershipRole::Owner);
})->group('security');

it('lets an owner close a review that removes another owner', function (): void {
    [$ownerId, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Subjects::class)->create('owner-x@acme.test', 'Owner X', 'supersecret123');
    app(Memberships::class)->add($org->id, $other->id, MembershipRole::Owner);

    openAccessReview()->assertSessionHasNoErrors();
    $campaign = CertificationCampaign::query()->where('organization_id', $org->id)->firstOrFail();
    $items = collect(app(AccessReviews::class)->itemsFor($campaign->id));
    $item = $items->firstWhere('subject_id', $other->id);
    decideAccessItem($campaign->id, $items->firstWhere('subject_id', $ownerId)->id, 'certified')->assertSessionHasNoErrors();

    decideAccessItem($campaign->id, $item->id, 'revoked')->assertSessionHasNoErrors();
    test()->from(route('governance.show', $campaign->id))->post(route('governance.close', $campaign->id))->assertSessionHasNoErrors();

    expect(app(Memberships::class)->of($org->id, $other->id))->toBeNull();
})->group('security');

/**
 * The same, for a role the organization may not assign — an environment-defined role an
 * environment administrator granted inside it. The People page refuses revoking it; the
 * review must too.
 */
it('never lets an organization review withdraw a role the organization does not assign', function (): void {
    [, $org] = actingAsRole(MembershipRole::Admin);
    $member = app(Subjects::class)->create('staffed@acme.test', 'Staffed', 'supersecret123');
    app(Memberships::class)->add($org->id, $member->id, MembershipRole::Member);
    $staff = app(Roles::class)->define(null, 'support-staff', tenantAssignable: false);
    app(Roles::class)->assign($org->id, $member->id, $staff->id);

    openAccessReview()->assertSessionHasNoErrors();
    $campaign = CertificationCampaign::query()->where('organization_id', $org->id)->firstOrFail();
    $roleItem = collect(app(AccessReviews::class)->itemsFor($campaign->id))->firstWhere(fn ($i) => $i->access_type === AccessKind::Role);

    decideAccessItem($campaign->id, $roleItem->id, 'revoked')->assertSessionHasErrors();
    test()->from(route('governance.show', $campaign->id))->post(route('governance.close', $campaign->id))->assertSessionHasErrors();

    expect(app(Roles::class)->assignmentsForSubject($org->id, $member->id))->not->toBe([]);
})->group('security');
