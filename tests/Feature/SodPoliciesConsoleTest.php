<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function sodAdmin(string $role = 'owner'): string
{
    $subject = app(Subjects::class)->create('sod@acme.test', 'Sod Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-sod'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    return $org->id;
}

it('defines a policy over two roles', function (): void {
    $orgId = sodAdmin();
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');

    Volt::test('sod-policies')
        ->set('name', 'PO vs pay')
        ->set('roleIds', [$a->id, $b->id])
        ->call('define')
        ->assertHasNoErrors();

    $policy = SodPolicy::query()->where('organization_id', $orgId)->firstOrFail();
    expect($policy->name)->toBe('PO vs pay');
    expect($policy->role_ids)->toEqualCanonicalizing([$a->id, $b->id]);
});

it('detects a violation', function (): void {
    $orgId = sodAdmin();
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs pay', [$a->id, $b->id]);

    app(Roles::class)->assign($orgId, 'user-1', $a->id);
    app(Roles::class)->assign($orgId, 'user-1', $b->id);

    expect(app(SegregationOfDuties::class)->scan($orgId))->toHaveCount(1);

    Volt::test('sod-policies')->assertSee('user-1');
});

it('forbids a non-admin member', function (): void {
    sodAdmin('member');

    Volt::test('sod-policies')->assertForbidden();
});
