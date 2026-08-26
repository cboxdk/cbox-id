<?php

declare(strict_types=1);

use App\Models\InvitationRoleGrant;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('parks chosen access roles at invite time', function (): void {
    Mail::fake();
    [, $org] = actingAsRole(MembershipRole::Owner);
    $role = app(Roles::class)->define($org->id, 'Editor');

    inviteToDirectory(['accessRoles' => [$role->id]])->assertSessionHasNoErrors();

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
    // Keyed to THIS invitation: a grant parked by (org, email) alone outlived the invite
    // that chose it, and the next invitation to the same address collected it.
    InvitationRoleGrant::query()->create([
        'invitation_id' => $pending->invitation->id,
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
            'invitation_id' => $pending->invitation->id,
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

/*
 * A REVOKED INVITATION TAKES ITS ROLES WITH IT.
 *
 * The grants were parked by `(organization_id, email, role_id)` — the invitation itself
 * was nowhere in the row — and revoking only updated the invitation. So the roles an
 * administrator had deliberately withdrawn sat there waiting for the NEXT invitation to
 * the same address to collect them: invite as finance-admin, think better of it and
 * revoke, invite again as a plain member, and they land holding finance-admin. Nothing in
 * the flow ever said so, and the person who revoked had every reason to believe they had.
 */
it('does not hand a later invitation the roles a revoked one had parked', function (): void {
    Mail::fake();
    [, $org] = actingAsRole(MembershipRole::Owner);
    $privileged = app(Roles::class)->define($org->id, 'Finance Admin');

    // Invited with a privileged role, then thought better of.
    inviteToDirectory(['accessRoles' => [$privileged->id]])->assertSessionHasNoErrors();

    $first = Invitation::query()->where('email', 'newbie@acme.test')->firstOrFail();

    test()->from(route('directory.members'))
        ->delete(route('directory.members.invitations.revoke', $first->id))
        ->assertSessionHasNoErrors();

    // Invited again, this time as a plain member with no access roles at all. Through the
    // service rather than the console because only it hands back the RAW token — the row
    // stores a hash, which is why the console's own flow mails the link instead.
    $second = app(Invitations::class)->invite($org->id, 'newbie@acme.test', MembershipRole::Member);

    $this->get('/invitations/'.$second->token.'/accept')->assertRedirect();

    $subject = app(Subjects::class)->findByEmail('newbie@acme.test');

    expect($subject)->not->toBeNull()
        ->and(RoleAssignment::query()
            ->where('organization_id', $org->id)
            ->where('user_id', $subject->id)
            ->where('role_id', $privileged->id)
            ->exists())->toBeFalse();
})->group('security');

it('clears the parked roles at the moment of revocation', function (): void {
    Mail::fake();
    [, $org] = actingAsRole(MembershipRole::Owner);
    $role = app(Roles::class)->define($org->id, 'Editor');

    inviteToDirectory(['accessRoles' => [$role->id]])->assertSessionHasNoErrors();

    $invitation = Invitation::query()->where('email', 'newbie@acme.test')->firstOrFail();
    expect(InvitationRoleGrant::query()->where('invitation_id', $invitation->id)->exists())->toBeTrue();

    test()->from(route('directory.members'))
        ->delete(route('directory.members.invitations.revoke', $invitation->id))
        ->assertSessionHasNoErrors();

    expect(InvitationRoleGrant::query()->where('invitation_id', $invitation->id)->exists())->toBeFalse();
})->group('security');

/*
 * AND ACCEPTANCE APPLIES ONE INVITATION'S ROLES, not the address's.
 *
 * Isolated deliberately: with revocation now clearing grants, the broad
 * `(organization_id, email)` select has nothing stale left to pick up, so reverting it
 * breaks no test — the two halves of the fix mask each other. This case involves no
 * revocation at all. Two invitations to the same address are live at once (an
 * administrator re-invites before the first expires, which nothing prevents), each
 * carrying different roles. Only the acceptance scope decides which set is applied.
 */
it('applies only the accepted invitation’s roles when two are live for one address', function (): void {
    Mail::fake();
    [, $org] = actingAsRole(MembershipRole::Owner);
    $privileged = app(Roles::class)->define($org->id, 'Finance Admin');
    $ordinary = app(Roles::class)->define($org->id, 'Editor');

    $first = app(Invitations::class)->invite($org->id, 'newbie@acme.test', MembershipRole::Member);
    InvitationRoleGrant::query()->create([
        'invitation_id' => $first->invitation->id,
        'organization_id' => $org->id,
        'email' => 'newbie@acme.test',
        'role_id' => $privileged->id,
    ]);

    $second = app(Invitations::class)->invite($org->id, 'newbie@acme.test', MembershipRole::Member);
    InvitationRoleGrant::query()->create([
        'invitation_id' => $second->invitation->id,
        'organization_id' => $org->id,
        'email' => 'newbie@acme.test',
        'role_id' => $ordinary->id,
    ]);

    $this->get('/invitations/'.$second->token.'/accept')->assertRedirect();

    $subject = app(Subjects::class)->findByEmail('newbie@acme.test');
    $holds = fn (string $roleId): bool => RoleAssignment::query()
        ->where('organization_id', $org->id)
        ->where('user_id', $subject->id)
        ->where('role_id', $roleId)
        ->exists();

    expect($holds($ordinary->id))->toBeTrue()
        ->and($holds($privileged->id))->toBeFalse()
        // And the other invitation's grant is untouched — accepting one must not consume
        // what belongs to the other.
        ->and(InvitationRoleGrant::query()->where('invitation_id', $first->invitation->id)->exists())->toBeTrue();
})->group('security');
