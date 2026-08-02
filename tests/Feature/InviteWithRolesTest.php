<?php

declare(strict_types=1);

use App\Models\InvitationRoleGrant;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

it('parks chosen access roles at invite time', function (): void {
    Mail::fake();
    [, $org] = actingAsRole(MembershipRole::Owner);
    $role = app(Roles::class)->define($org->id, 'Editor');

    Volt::test('members')
        ->set('inviteEmail', 'newbie@acme.test')
        ->set('inviteRole', 'member')
        ->set('inviteAccessRoles', [$role->id])
        ->call('invite')
        ->assertHasNoErrors();

    expect(InvitationRoleGrant::query()
        ->where('organization_id', $org->id)
        ->where('email', 'newbie@acme.test')
        ->where('role_id', $role->id)
        ->exists())->toBeTrue();
});

it('applies parked access roles when the invitation is accepted', function (): void {
    Mail::fake();
    [, $org] = actingAsRole(MembershipRole::Owner);
    $role = app(Roles::class)->define($org->id, 'Editor');

    $pending = app(Invitations::class)->invite($org->id, 'newbie@acme.test', MembershipRole::Member);
    InvitationRoleGrant::query()->create([
        'organization_id' => $org->id,
        'email' => 'newbie@acme.test',
        'role_id' => $role->id,
    ]);

    $this->get('/invitations/'.$pending->token.'/accept')->assertRedirect();

    $subject = app(Subjects::class)->findByEmail('newbie@acme.test');
    expect($subject)->not->toBeNull()
        ->and(RoleAssignment::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $subject->id)
            ->where('role_id', $role->id)
            ->exists())->toBeTrue()
        // The parked grants are cleared after applying.
        ->and(InvitationRoleGrant::query()->where('email', 'newbie@acme.test')->exists())->toBeFalse();
});

/**
 * A role retired between the invitation and the click must not consume the invitation.
 *
 * Acceptance commits in its own transaction — membership written, invitation marked
 * accepted — and only then are the parked roles applied. An uncaught throw there is not
 * a 500 but a permanent one: the invitee is a member holding an arbitrary prefix of
 * their roles, is never signed in, and every retry answers "That invitation is invalid
 * or has expired". Worse, the parked grant row survives, so the next invitation to the
 * same address burns on the same row. The only ways out were database surgery or
 * un-retiring the role.
 *
 * The trigger is ordinary: an app ships a manifest without a role it used to declare, or
 * an environment admin deletes one. The console pickers filter retired roles, so the
 * only way to hold one is exactly this — parked at invite time, replayed later.
 */
it('accepts an invitation whose parked role was retired in the meantime', function (): void {
    Mail::fake();
    [, $org] = actingAsRole(MembershipRole::Owner);

    $live = app(Roles::class)->define($org->id, 'Editor');
    $retired = app(Roles::class)->define($org->id, 'Legacy Reviewer');

    $pending = app(Invitations::class)->invite($org->id, 'newbie@acme.test', MembershipRole::Member);

    foreach ([$live->id, $retired->id] as $roleId) {
        InvitationRoleGrant::query()->create([
            'organization_id' => $org->id,
            'email' => 'newbie@acme.test',
            'role_id' => $roleId,
        ]);
    }

    Role::query()->whereKey($retired->id)->update(['orphaned_at' => now()]);

    $this->get('/invitations/'.$pending->token.'/accept')
        ->assertRedirect(route('dashboard'));

    $subject = app(Subjects::class)->findByEmail('newbie@acme.test');

    expect($subject)->not->toBeNull();

    // The live role still lands — a withheld grant must not cost the others.
    expect(RoleAssignment::query()
        ->where('organization_id', $org->id)
        ->where('user_id', $subject->id)
        ->where('role_id', $live->id)
        ->exists())->toBeTrue('a retired sibling grant swallowed the live one');

    expect(RoleAssignment::query()
        ->where('user_id', $subject->id)
        ->where('role_id', $retired->id)
        ->exists())->toBeFalse();

    // And the poison row is gone, so a future invitation to this address is clean.
    expect(InvitationRoleGrant::query()
        ->where('organization_id', $org->id)
        ->where('email', 'newbie@acme.test')
        ->exists())->toBeFalse();
});
