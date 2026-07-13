<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Enums\OrganizationType;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// The operator console re-checks operator auth on every request (incl. Livewire
// actions), so every test here operates as a signed-in operator.
beforeEach(function (): void {
    $op = app(PlatformOperators::class)->create('org-admin@platform.test', 'a-strong-operator-pass', 'Op');
    session([OperatorAuth::SESSION_KEY => $op->id]);
});

it('creates organizations with a type and parent, laid out as a hierarchy', function (): void {
    $reseller = app(Organizations::class)->create(
        new NewOrganization('Reseller Co', 'reseller-co', OrganizationType::Reseller),
    );

    Volt::test('operator.organizations')
        ->set('name', 'Customer Co')
        ->set('type', 'customer')
        ->set('parentId', $reseller->id)
        ->call('create')
        ->assertHasNoErrors();

    $rows = collect(Volt::test('operator.organizations')->viewData('rows'))->keyBy('name');

    expect($rows['Reseller Co']['depth'])->toBe(0)
        ->and($rows['Reseller Co']['type'])->toBe('reseller')
        ->and($rows['Customer Co']['depth'])->toBe(1)
        ->and($rows['Customer Co']['parent_id'])->toBe($reseller->id);
});

it('suspends and reactivates an organization', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));

    Volt::test('operator.organizations')->call('toggleStatus', $org->id);
    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Suspended);

    Volt::test('operator.organizations')->call('toggleStatus', $org->id);
    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Active);
});

it('reparents an organization within the tree', function (): void {
    $orgs = app(Organizations::class);
    $a = $orgs->create(new NewOrganization('A', 'a'));
    $b = $orgs->create(new NewOrganization('B', 'b'));

    Volt::test('operator.organizations')->call('reparent', $b->id, $a->id);

    expect(Organization::query()->find($b->id)->parent_id)->toBe($a->id);
});

it('refuses a reparent that would create a cycle', function (): void {
    $orgs = app(Organizations::class);
    $parent = $orgs->create(new NewOrganization('Parent', 'parent'));
    $child = $orgs->create(new NewOrganization('Child', 'child', parentId: $parent->id));

    // Making the parent a child of its own descendant would loop the tree.
    Volt::test('operator.organizations')->call('reparent', $parent->id, $child->id);

    expect(Organization::query()->find($parent->id)->parent_id)->toBeNull();
});
