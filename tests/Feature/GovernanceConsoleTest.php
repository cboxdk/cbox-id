<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\AccessKind;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function govAdmin(MembershipRole $role = MembershipRole::Owner): string
{
    $subject = app(Subjects::class)->create('gov@acme.test', 'Gov Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-gov'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    return $org->id;
}

it('opens a review that snapshots access, and applies a revoke on close', function (): void {
    $orgId = govAdmin();
    $role = app(Roles::class)->define($orgId, 'engineer');
    app(Roles::class)->assign($orgId, 'engineer-1', $role->id);

    // Open a review from the console. One component now serves both planes, and the
    // routable index/new/show shape won over the single page — a campaign URL is
    // something you send to a reviewer.
    Volt::test('console.governance.create')->set('name', 'Q3 review')->call('open')->assertHasNoErrors();

    $campaign = CertificationCampaign::query()->where('organization_id', $orgId)->firstOrFail();
    $items = app(AccessReviews::class)->itemsFor($campaign->id);
    // One role assignment (engineer-1) + the admin's own membership.
    expect($items)->toHaveCount(2);

    $roleItem = collect($items)->firstWhere(fn ($i) => $i->access_type === AccessKind::Role);

    // Revoke the role item, then close — the underlying role assignment is removed.
    Volt::test('console.governance.show', ['campaign' => $campaign->id])
        ->call('revoke', $roleItem->id)
        ->call('close', $campaign->id)
        ->assertHasNoErrors();

    expect(app(Roles::class)->assignmentsForSubject($orgId, 'engineer-1'))->toBe([]);
});

it('forbids a non-admin member', function (): void {
    govAdmin(MembershipRole::Member);

    Volt::test('console.governance.index')->assertForbidden();
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

    // Forty people holding the role, so both the item count and the distinct-subject
    // count exceed a page — a fixture inside one page would pass against either defect.
    foreach (range(1, 40) as $i) {
        $subject = app(Subjects::class)->create("reviewee-{$i}@acme.test", "Reviewee {$i}");
        app(Roles::class)->assign($orgId, $subject->id, $role->id);
    }

    Volt::test('console.governance.create')->set('name', 'Big review')->call('open')->assertHasNoErrors();
    $campaign = CertificationCampaign::query()->where('organization_id', $orgId)->firstOrFail();

    // Warm: a first render pays for schema and config reads that have nothing to do with
    // this page, and comparing a cold render against a warm one measures the wrong thing.
    Volt::test('console.governance.show', ['campaign' => $campaign->id])->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $page = Volt::test('console.governance.show', ['campaign' => $campaign->id]);

    // Twenty-five rows on screen out of forty-one, and a flat cost to draw them — the
    // row count is what stops this passing against a page that rendered nothing.
    // The subject labels are names, and the snapshot is ordered by id — so the first
    // page holds the earliest-created assignments and the fortieth is not among them.
    expect($page->html())->toContain('Reviewee 1')
        ->and($page->html())->not->toContain('Reviewee 40')
        ->and($queries)->toBeLessThan(15);
});
