<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

function dashboardOwner(): array
{
    $owner = app(Subjects::class)->create('owner@acme.test', 'Olive Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    app(Memberships::class)->add($org->id, $owner->id, 'owner');
    $session = app(SessionManager::class)->start($owner->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($owner, $session, $org, MembershipRole::Owner);

    return [$org, $owner];
}

it('renders the recent-activity feed with member names, not raw ids', function (): void {
    [$org] = dashboardOwner();

    // Adding a member writes an audit entry whose target is the new member's id.
    $member = app(Subjects::class)->create('grace@acme.test', 'Grace Hopper', 'supersecret123');
    app(Memberships::class)->add($org->id, $member->id, 'member');

    Volt::test('dashboard')
        ->assertSee('Grace Hopper')      // resolved name is shown
        ->assertDontSee($member->id);    // the raw ULID is not
});

it('falls back gracefully when a target cannot be resolved', function (): void {
    dashboardOwner();

    // No members added beyond the owner — the feed still renders (org-created entry).
    Volt::test('dashboard')
        ->assertOk()
        ->assertSee('Recent activity');
});
