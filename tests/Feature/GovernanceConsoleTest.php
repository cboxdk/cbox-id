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
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function govAdmin(string $role = 'owner'): string
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

    // Open a review from the console.
    $component = Volt::test('governance')->set('name', 'Q3 review')->call('open')->assertHasNoErrors();

    $campaign = CertificationCampaign::query()->where('organization_id', $orgId)->firstOrFail();
    $items = app(AccessReviews::class)->itemsFor($campaign->id);
    // One role assignment (engineer-1) + the admin's own membership.
    expect($items)->toHaveCount(2);

    $roleItem = collect($items)->firstWhere(fn ($i) => $i->access_type === AccessKind::Role);

    // Revoke the role item, then close — the underlying role assignment is removed.
    $component->call('revoke', $roleItem->id)->call('close', $campaign->id)->assertHasNoErrors();

    expect(app(Roles::class)->assignmentsForSubject($orgId, 'engineer-1'))->toBe([]);
});

it('forbids a non-admin member', function (): void {
    govAdmin('member');

    Volt::test('governance')->assertForbidden();
});
