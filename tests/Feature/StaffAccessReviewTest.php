<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Governance\Contracts\AccessReviews;
use Cbox\Id\Governance\Enums\AccessKind;
use Cbox\Id\Governance\Models\CertificationCampaign;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Access reviews of staff roles
|--------------------------------------------------------------------------
| The largest grants in an environment — roles held in every organization at once — were
| in no review at all, because a review belonged to one organization. The environment
| console now opens a review with no organization, whose decisions take the grant back
| everywhere; an organization's own review never lists them.
*/

/**
 * A person holding a staff role and a role in one organization.
 *
 * @return array{sam: string, support: string, engineer: string, org: string}
 */
function staffReviewFixture(): array
{
    $roles = app(Roles::class);

    $sam = app(Subjects::class)->create('sam@vendor.test', 'Sam Support', 'a-strong-unbreached-passphrase')->id;
    $org = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-review'));
    app(Memberships::class)->add($org->id, $sam, MembershipRole::Member);

    $support = $roles->define(null, 'Support');
    $engineer = $roles->define($org->id, 'Engineer');

    $roles->assignEverywhere($sam, $support->id);
    $roles->assign($org->id, $sam, $engineer->id);

    return ['sam' => $sam, 'support' => $support->id, 'engineer' => $engineer->id, 'org' => $org->id];
}

it('opens a review of staff roles from the environment console, and a revoke takes the role back everywhere', function (): void {
    crudSetup();
    ['sam' => $sam, 'support' => $support] = staffReviewFixture();

    openAccessReview(['name' => 'Q3 staff access', 'covers' => 'staff'], 'environment.governance')
        ->assertSessionHasNoErrors();

    $campaign = CertificationCampaign::query()->whereNull('organization_id')->firstOrFail();
    $items = app(AccessReviews::class)->itemsFor($campaign->id);

    // Only the staff grant — never the organization's own role or membership.
    expect(collect($items)->map(fn ($item): string => $item->access_type->value)->all())
        ->toBe([AccessKind::EnvironmentRole->value]);

    $props = $this->get(route('environment.governance.show', $campaign->id))->assertOk()->inertiaProps();

    expect($props['review']['staff'])->toBeTrue()
        ->and($props['items'][0]['kind'])->toBe('Staff role')
        ->and($props['items'][0]['access'])->toBe('Support')
        ->and($props['items'][0]['subject'])->toBe('Sam Support');

    decideAccessItem($campaign->id, $items[0]->id, 'revoked', 'environment.governance')->assertSessionHasNoErrors();

    // Recorded, not applied, until the review closes.
    expect(app(Roles::class)->everywhereFor($sam))->toBe([$support]);

    $this->from(route('environment.governance.show', $campaign->id))
        ->post(route('environment.governance.close', $campaign->id))
        ->assertSessionHasNoErrors();

    expect(app(Roles::class)->everywhereFor($sam))->toBe([]);
});

it('keeps a staff review visible while an organization is chosen in the console header', function (): void {
    crudSetup();
    ['org' => $org] = staffReviewFixture();

    $staffReview = app(AccessReviews::class)->open(null, 'Staff access');

    $this->post(route('environment.acting-organization.choose'), ['organization' => $org]);

    $this->get(route('environment.governance.show', $staffReview->id))->assertOk();

    $listed = collect($this->get(route('environment.governance'))->assertOk()->inertiaProps('reviews'));

    expect($listed->firstWhere('id', $staffReview->id)['staff'] ?? null)->toBeTrue();
});

/**
 * THE ORGANIZATION CONSOLE NEVER REACHES ONE. A tenant's reviewer must not see the
 * vendor's staff grants, let alone revoke them — and a staff review's id handed to the
 * organization console resolves to nothing.
 */
it('never shows a staff review, or a staff grant, on the organization console', function (): void {
    [$admin, $org] = actingAsRole(MembershipRole::Owner);
    $orgId = $org->id;

    $roles = app(Roles::class);
    $support = $roles->define(null, 'Support');
    $roles->assignEverywhere($admin, $support->id);

    $staffReview = app(AccessReviews::class)->open(null, 'Staff access');

    $this->get(route('governance.show', $staffReview->id))->assertNotFound();
    $this->post(route('governance.close', $staffReview->id))->assertNotFound();

    expect(collect($this->get(route('governance'))->assertOk()->inertiaProps('reviews'))->pluck('id')->all())
        ->not->toContain($staffReview->id);

    // Opening one is refused rather than quietly read as this organization's review…
    openAccessReview(['name' => 'Sneaky', 'covers' => 'staff'])->assertForbidden();

    // …and this organization's own review never snapshots the staff grant.
    openAccessReview(['name' => 'Ours'])->assertSessionHasNoErrors();

    $ours = CertificationCampaign::query()->where('organization_id', $orgId)->firstOrFail();

    expect(collect(app(AccessReviews::class)->itemsFor($ours->id))->pluck('access_ref')->all())
        ->not->toContain($support->id);

    expect($roles->everywhereFor($admin))->toBe([$support->id]);
})->group('security');

it('offers the choice only on the environment console', function (): void {
    crudSetup();

    $props = $this->get(route('environment.governance.create', ['review' => 'staff']))->assertOk()->inertiaProps();

    expect($props['canReviewStaff'])->toBeTrue()
        ->and($props['covers'])->toBe('staff');
});
