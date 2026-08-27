<?php

declare(strict_types=1);

use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Every route on this plane refuses a request that is not an operator's — the read and
// each write alike — so every test here operates as a signed-in operator. The refusals
// themselves live in PlatformOrganizationDetailTest.
beforeEach(function (): void {
    actAsOperator('org-admin@platform.test');
});

it('creates organizations with a type and parent, laid out as a hierarchy', function (): void {
    $reseller = app(Organizations::class)->create(
        new NewOrganization('Reseller Co', 'reseller-co', OrganizationType::Reseller),
    );

    createTenantOrganization([
        'name' => 'Customer Co',
        'type' => 'customer',
        'parentId' => $reseller->id,
    ])->assertSessionHasNoErrors();

    // The DEPTH is the assertion rather than the column: the page renders the tree, and a
    // parent_id written without the closure rows would list the child as a root.
    $rows = collect(platformOrganizations()['organizations'])->keyBy('name');

    expect($rows['Reseller Co']['depth'])->toBe(0)
        ->and($rows['Reseller Co']['type'])->toBe('reseller')
        ->and($rows['Customer Co']['depth'])->toBe(1)
        ->and($rows['Customer Co']['parentId'])->toBe($reseller->id);
});

it('suspends and reactivates an organization', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));

    toggleTenantOrganization($org->id)->assertSessionHasNoErrors();
    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Suspended);

    toggleTenantOrganization($org->id);
    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Active);
});

it('reparents an organization within the tree', function (): void {
    $orgs = app(Organizations::class);
    $a = $orgs->create(new NewOrganization('A', 'a'));
    $b = $orgs->create(new NewOrganization('B', 'b'));

    reparentOrganization($b->id, $a->id)->assertSessionHasNoErrors();

    expect(Organization::query()->find($b->id)->parent_id)->toBe($a->id)
        // …and the tree the page renders agrees, which is the half a parent_id alone does
        // not prove.
        ->and(collect(platformOrganizations()['organizations'])->firstWhere('id', $b->id)['depth'])
        ->toBe(1);
});

it('refuses a reparent that would create a cycle', function (): void {
    $orgs = app(Organizations::class);
    $parent = $orgs->create(new NewOrganization('Parent', 'parent'));
    $child = $orgs->create(new NewOrganization('Child', 'child', parentId: $parent->id));

    /*
     * Making the parent a child of its own descendant would loop the tree. `move()` guards
     * the closure table itself, so nothing is corrupted either way — but the REFUSAL IS
     * SAID: a control that appears to do nothing is one the operator presses again.
     */
    reparentOrganization($parent->id, $child->id)->assertSessionHasErrors('parentId');

    expect(Organization::query()->find($parent->id)->parent_id)->toBeNull();
});
