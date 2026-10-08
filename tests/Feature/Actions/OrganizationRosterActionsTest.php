<?php

declare(strict_types=1);

use App\Actions\CustomerApiKeys\RevokeCustomerApiKey;
use App\Actions\Invitations\SendInvitation;
use App\Actions\Members\ChangeMemberRole;
use App\Actions\Members\GrantMemberRole;
use App\Actions\Members\RemoveMember;
use App\Actions\Members\RevokeMemberRole;
use App\Actions\Members\TenantRoster;
use App\Actions\Organizations\TransferOwnership;
use App\Models\InvitationContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\Console\ConsoleScope;
use App\Platform\EnvironmentApiContext;
use App\Platform\OAuth\ValueObjects\OrganizationChoice;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\FakeDelegatedTokens;

/*
|--------------------------------------------------------------------------
| An organization's own roster, as actions — from inside the organization.
|--------------------------------------------------------------------------
|
| The People page and Member API keys run the same actions as the environment console, the
| management API and an agent. From inside the organization — its console, or a token one
| of its people signed in for — the tenant's rules hold: nobody reaches another
| organization, an admin cannot touch an owner, nobody removes themselves, only the owner
| hands it over, staff roles are not the tenant's to grant, and a customer's roster is
| administered from Workspace › Team. The environment's own authority keeps its rules.
*/

beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    Mail::fake();
    installedDeployment();
});

/** A person on the acting organization's roster. */
function rosterColleague(string $organizationId, MembershipRole $role, string $email): string
{
    $subject = app(Subjects::class)->create($email, ucfirst(Str::before($email, '@')));
    app(Memberships::class)->add($organizationId, $subject->id, $role);

    return $subject->id;
}

function rosterOrganization(string $name = 'Globex'): Organization
{
    return app(Organizations::class)->create(new NewOrganization($name, Str::slug($name).'-'.Str::lower(Str::random(4))));
}

function rosterConsole(): ConsoleSessionPrincipal
{
    return new ConsoleSessionPrincipal(app(ConsoleScope::class));
}

/**
 * A person's token, as {@see DelegatedAccess} resolves one: bound to $organizationId, where
 * they hold $role.
 *
 * @param  list<string>  $scopes
 */
function rosterToken(string $subjectId, Organization $organization, MembershipRole $role, array $scopes = ['members:write', 'roles:write', 'invitations:write', 'organizations:write', 'api_keys:write']): DelegatedTokenPrincipal
{
    return new DelegatedTokenPrincipal(
        subjectId: $subjectId,
        personName: 'Ada',
        clientId: 'claude-code',
        clientName: 'Claude Code',
        environmentId: 'env_test',
        scopes: $scopes,
        organization: new OrganizationChoice($organization->id, $organization->name, $role),
        customerConsole: false,
    );
}

function rosterAudit(string $action): ?AuditEntry
{
    return AuditEntry::query()->where('action', $action)->orderByDesc('id')->first();
}

/*
|--------------------------------------------------------------------------
| The People page runs the actions, as the person
|--------------------------------------------------------------------------
*/

it('runs every People page write through its action, recorded as the person', function (): void {
    [$ownerId, $org] = actingAsRole(MembershipRole::Owner);
    $dana = rosterColleague($org->id, MembershipRole::Member, 'dana@acme.test');
    $erin = rosterColleague($org->id, MembershipRole::Member, 'erin@acme.test');

    // Invited, and signed by the person — their own subject id and name, from THIS
    // environment, not "An administrator" from a platform-root lookup that finds nobody.
    inviteToDirectory(['email' => 'newbie@acme.test'])->assertSessionHasNoErrors()->assertSessionHas('status', 'Invitation sent to newbie@acme.test.');

    $invitation = Invitation::query()->where('email', 'newbie@acme.test')->sole();

    expect($invitation->invited_by)->toBe($ownerId)
        ->and(InvitationContext::query()->where('invitation_id', $invitation->id)->value('invited_by_name'))->toBe('Owner')
        ->and(rosterAudit('organization.invitation_created')?->actor_id)->toBe($ownerId);

    test()->from(route('directory.members'))->post(route('directory.members.invitations.resend', $invitation->id))
        ->assertSessionHas('status', 'Invitation sent again to newbie@acme.test.');

    // A fresh link is a new invitation; the old id stops working.
    $fresh = Invitation::query()->where('email', 'newbie@acme.test')->whereKeyNot($invitation->id)->sole();

    test()->from(route('directory.members'))->delete(route('directory.members.invitations.revoke', $fresh->id))
        ->assertSessionHas('status', 'Invitation revoked. That link no longer works.');

    expect(rosterAudit('organization.invitation_revoked')?->actor_id)->toBe($ownerId);

    test()->from(route('directory.members'))->patch(route('directory.members.role', $dana), ['role' => 'admin'])
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Built-in role updated.');

    // The framework's own entry, exactly as the page recorded it before it was an action.
    expect(app(Memberships::class)->of($org->id, $dana)?->role)->toBe(MembershipRole::Admin)
        ->and(rosterAudit('organization.member_role_changed'))->not->toBeNull();

    $reviewer = app(Roles::class)->define($org->id, 'Reviewer');

    setDirectoryAccessRole($dana, $reviewer->id, true)->assertSessionHasNoErrors()->assertSessionHas('status', 'Role granted.');
    expect(RoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $dana)->pluck('role_id')->all())->toBe([$reviewer->id]);

    setDirectoryAccessRole($dana, $reviewer->id, false)->assertSessionHasNoErrors()->assertSessionHas('status', 'Role revoked.');
    expect(RoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $dana)->exists())->toBeFalse();

    test()->from(route('directory.members'))->delete(route('directory.members.remove', $erin))
        ->assertSessionHasNoErrors()->assertSessionHas('status', 'Member removed.');

    expect(app(Memberships::class)->of($org->id, $erin))->toBeNull()
        ->and(rosterAudit('organization.member_removed')?->target_id)->toBe($erin);

    test()->from(route('directory.members'))->post(route('directory.members.transfer-ownership', $dana))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Ownership transferred to Dana. You are now an admin.');

    expect(app(Memberships::class)->owners($org->id))->toBe([$dana])
        ->and(app(Memberships::class)->of($org->id, $ownerId)?->role)->toBe(MembershipRole::Admin)
        ->and(AuditEntry::query()->where('action', 'organization.ownership_transferred')->count())->toBe(1)
        ->and(rosterAudit('organization.ownership_transferred')?->actor_id)->toBe($ownerId);
});

it('revokes a member\'s API key through the action, and says nothing about one that is not here', function (): void {
    $fixture = appKeyFixture();
    $key = mintAppKey($fixture, $fixture['ada']);
    $elsewhere = appKeyFixture('globex-roster');
    $theirs = mintAppKey($elsewhere, $elsewhere['ada']);

    $admin = app(Subjects::class)->create('admin@acme-roster.test', 'Admin', 'supersecret123');
    app(Memberships::class)->add($fixture['org']->id, $admin->id, MembershipRole::Admin);
    signInKeyHolder($admin->id, $fixture['org']);

    $this->from(route('directory.api-keys'))->delete(route('directory.api-keys.revoke', $key->id))
        ->assertRedirect(route('directory.api-keys'))
        ->assertSessionHas('status');

    // Another organization's key: quietly nothing, as the page always answered it.
    $this->from(route('directory.api-keys'))->delete(route('directory.api-keys.revoke', $theirs->id))
        ->assertRedirect(route('directory.api-keys'))
        ->assertSessionMissing('status');

    expect(app(CustomerApiKeys::class)->find($key->id)?->revoked_at)->not->toBeNull()
        ->and(app(CustomerApiKeys::class)->find($theirs->id)?->revoked_at)->toBeNull()
        ->and(rosterAudit('api_key.revoked')?->actor_id)->toBe($admin->id);
});

/*
|--------------------------------------------------------------------------
| The tenant's rules, from inside the organization
|--------------------------------------------------------------------------
*/

it('never lets an admin demote or remove an owner, from the page or the runner', function (): void {
    [, $org] = actingAsRole(MembershipRole::Admin);
    $boss = rosterColleague($org->id, MembershipRole::Owner, 'boss@acme.test');

    test()->from(route('directory.members'))->patch(route('directory.members.role', $boss), ['role' => 'member'])->assertForbidden();
    test()->from(route('directory.members'))->delete(route('directory.members.remove', $boss))->assertForbidden();

    $runner = app(ActionRunner::class);

    expect(fn () => $runner->run(ChangeMemberRole::class, rosterConsole(), ['organization_id' => $org->id, 'user_id' => $boss, 'role' => 'member']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $runner->run(RemoveMember::class, rosterConsole(), ['organization_id' => $org->id, 'user_id' => $boss]))
        ->toThrow(AuthorizationException::class)
        ->and(app(Memberships::class)->of($org->id, $boss)?->role)->toBe(MembershipRole::Owner);
})->group('security');

it('refuses removing yourself, and points to leaving', function (): void {
    [$meId, $org] = actingAsRole(MembershipRole::Owner);
    rosterColleague($org->id, MembershipRole::Owner, 'co-owner@acme.test');

    test()->from(route('directory.members'))->delete(route('directory.members.remove', $meId))
        ->assertSessionHasErrors(['member' => 'To remove yourself, use "Leave organization".']);

    try {
        app(ActionRunner::class)->run(RemoveMember::class, rosterConsole(), ['organization_id' => $org->id, 'user_id' => $meId]);
        $refused = null;
    } catch (ActionRefused $refused) {
    }

    expect($refused?->error)->toBe('remove_self')
        ->and(app(Memberships::class)->of($org->id, $meId))->not->toBeNull();
});

it('lets only the owner hand the organization over, and handing it to yourself is nothing', function (): void {
    [$adminId, $org] = actingAsRole(MembershipRole::Admin);
    $boss = rosterColleague($org->id, MembershipRole::Owner, 'boss@acme.test');

    test()->from(route('directory.members'))->post(route('directory.members.transfer-ownership', $adminId))->assertForbidden();

    expect(fn () => app(ActionRunner::class)->run(TransferOwnership::class, rosterConsole(), ['id' => $org->id, 'user_id' => $adminId]))
        ->toThrow(AuthorizationException::class)
        ->and(app(Memberships::class)->owners($org->id))->toBe([$boss]);

    // The owner, to themself: no change, no entry, no message.
    [$ownerId, $own] = actingAsRole(MembershipRole::Owner);

    test()->from(route('directory.members'))->post(route('directory.members.transfer-ownership', $ownerId))
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('status');

    expect(app(Memberships::class)->owners($own->id))->toBe([$ownerId])
        ->and(AuditEntry::query()->where('action', 'organization.ownership_transferred')->exists())->toBeFalse();
});

it('grants only what the tenant may hand out, and says the same for a staff role and no role', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $dana = rosterColleague($org->id, MembershipRole::Member, 'dana@acme.test');
    $staff = app(Roles::class)->define(null, 'Vendor support', tenantAssignable: false);

    setDirectoryAccessRole($dana, $staff->id, true)->assertSessionHasErrors(['role' => OrgAccessRoles::NOT_OFFERED]);
    setDirectoryAccessRole($dana, 'no-such-role', true)->assertSessionHasErrors(['role' => OrgAccessRoles::NOT_OFFERED]);

    $runner = app(ActionRunner::class);

    foreach ([GrantMemberRole::class, RevokeMemberRole::class] as $action) {
        try {
            $runner->run($action, rosterConsole(), ['organization_id' => $org->id, 'user_id' => $dana, 'role_id' => $staff->id]);
            $refused = null;
        } catch (ActionRefused $refused) {
        }

        expect($refused?->error)->toBe('role_not_assignable')
            ->and($refused?->getMessage())->toBe(OrgAccessRoles::NOT_OFFERED);
    }

    expect(RoleAssignment::query()->where('user_id', $dana)->exists())->toBeFalse();
})->group('security');

it('refuses a customer\'s roster writes from its own People page, with where it is managed', function (): void {
    ['subjectId' => $ownerId, 'organization' => $customer] = provisionAccount();
    [$membership, $member] = addMember($customer->id, MembershipRole::Member, 'dev@acme.example');
    signInAsMember($ownerId);

    inviteToDirectory(['email' => 'new@acme.example'])->assertSessionHasErrors(['email' => TenantRoster::MANAGED_ELSEWHERE]);
    test()->from(route('directory.members'))->patch(route('directory.members.role', $member), ['role' => 'admin'])
        ->assertSessionHasErrors(['role' => TenantRoster::MANAGED_ELSEWHERE]);
    test()->from(route('directory.members'))->delete(route('directory.members.remove', $member))
        ->assertSessionHasErrors(['member' => TenantRoster::MANAGED_ELSEWHERE]);
    test()->from(route('directory.members'))->post(route('directory.members.transfer-ownership', $member))
        ->assertSessionHasErrors(['member' => TenantRoster::MANAGED_ELSEWHERE]);
    test()->from(route('directory.members'))->post(route('directory.members.leave'))
        ->assertSessionHasErrors(['member' => TenantRoster::MANAGED_ELSEWHERE]);

    expect(freshMembership($membership)?->role)->toBe(MembershipRole::Member)
        ->and(Invitation::query()->withoutGlobalScopes()->where('email', 'new@acme.example')->exists())->toBeFalse();
})->group('security');

/*
|--------------------------------------------------------------------------
| Confined: never another organization
|--------------------------------------------------------------------------
*/

it('keeps a person on the organization console to their own organization, whatever id they name', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $globex = rosterOrganization();
    $victim = rosterColleague($globex->id, MembershipRole::Member, 'victim@globex.test');
    $keys = appKeyFixture('globex-confined');
    $victimKey = mintAppKey($keys, $keys['ada']);
    $runner = app(ActionRunner::class);

    // Another organization answers exactly as one that does not exist: a 404 when the URL
    // names it, the unknown-organization field error when the body does.
    foreach ([
        [ChangeMemberRole::class, ['organization_id' => $globex->id, 'user_id' => $victim, 'role' => 'admin'], 404],
        [RemoveMember::class, ['organization_id' => $globex->id, 'user_id' => $victim], 404],
        [SendInvitation::class, ['organization_id' => $globex->id, 'email' => 'mole@globex.test'], 404],
        [TransferOwnership::class, ['id' => $globex->id, 'user_id' => $victim], 404],
        [RevokeCustomerApiKey::class, ['id' => $victimKey->id, 'organization_id' => $keys['org']->id], 422],
    ] as [$action, $input, $status]) {
        try {
            $runner->run($action, rosterConsole(), $input);
            $refused = null;
        } catch (ActionRefused $refused) {
        }

        expect($refused?->status)->toBe($status);
    }

    // Naming no organization is the environment's whole authority: never theirs.
    expect(fn () => $runner->run(RevokeCustomerApiKey::class, rosterConsole(), ['id' => $victimKey->id]))->toThrow(AuthorizationException::class);

    // A member of another organization named under their own: not found.
    expect(fn () => $runner->run(ChangeMemberRole::class, rosterConsole(), ['organization_id' => $org->id, 'user_id' => $victim, 'role' => 'admin']))
        ->toThrow(ActionRefused::class, 'Member not found.')
        ->and(app(Memberships::class)->of($globex->id, $victim)?->role)->toBe(MembershipRole::Member)
        ->and(app(CustomerApiKeys::class)->find($victimKey->id)?->revoked_at)->toBeNull();
})->group('security');

it('holds a token one of an organization\'s admins signed in for to the same rules, in that organization only', function (): void {
    $acme = rosterOrganization('Acme');
    $globex = rosterOrganization('Globex');
    $ada = rosterColleague($acme->id, MembershipRole::Admin, 'ada@acme.test');
    $boss = rosterColleague($acme->id, MembershipRole::Owner, 'boss@acme.test');
    $dana = rosterColleague($acme->id, MembershipRole::Member, 'dana@acme.test');
    $victim = rosterColleague($globex->id, MembershipRole::Member, 'victim@globex.test');
    $token = rosterToken($ada, $acme, MembershipRole::Admin);
    $runner = app(ActionRunner::class);

    // What the REST and MCP doors do with a token they accepted: the trail is the person's.
    app(EnvironmentApiContext::class)->setDelegated($token);

    // Their own organization's roster: yes — and the trail names the person.
    $runner->run(ChangeMemberRole::class, $token, ['organization_id' => $acme->id, 'user_id' => $dana, 'role' => 'admin']);

    expect(app(Memberships::class)->of($acme->id, $dana)?->role)->toBe(MembershipRole::Admin)
        ->and(rosterAudit('organization.member_role_changed')?->actor_id)->toBe($ada);

    // An invitation is signed with their name, not the environment's.
    $runner->run(SendInvitation::class, $token, ['organization_id' => $acme->id, 'email' => 'newbie@acme.test']);
    expect(Invitation::query()->where('email', 'newbie@acme.test')->value('invited_by'))->toBe($ada);

    // The tenant's rules: an admin does not touch the owner, and cannot hand out a staff role.
    expect(fn () => $runner->run(ChangeMemberRole::class, $token, ['organization_id' => $acme->id, 'user_id' => $boss, 'role' => 'member']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $runner->run(GrantMemberRole::class, $token, ['organization_id' => $acme->id, 'user_id' => $dana, 'role_id' => app(Roles::class)->define(null, 'Support', tenantAssignable: false)->id]))
        ->toThrow(ActionRefused::class, OrgAccessRoles::NOT_OFFERED);

    // Another organization: never, whichever action — and not found, as an unknown one is.
    foreach ([
        [ChangeMemberRole::class, ['organization_id' => $globex->id, 'user_id' => $victim, 'role' => 'admin']],
        [RemoveMember::class, ['organization_id' => $globex->id, 'user_id' => $victim]],
        [SendInvitation::class, ['organization_id' => $globex->id, 'email' => 'mole@globex.test']],
    ] as [$action, $input]) {
        expect(fn () => $runner->run($action, $token, $input))->toThrow(ActionRefused::class, 'Organization not found.');
    }

    expect(app(Memberships::class)->of($globex->id, $victim))->not->toBeNull()
        ->and(app(Memberships::class)->of($acme->id, $boss)?->role)->toBe(MembershipRole::Owner);

    // A plain member's token holds no roster rights at all, whatever its scopes.
    expect(fn () => $runner->run(RemoveMember::class, rosterToken($dana, $acme, MembershipRole::Member), ['organization_id' => $acme->id, 'user_id' => $ada]))
        ->toThrow(AuthorizationException::class);
})->group('security');

/*
|--------------------------------------------------------------------------
| The environment's own authority keeps its rules
|--------------------------------------------------------------------------
*/

it('leaves the environment console and its keys as they were', function (): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));

    $org = rosterOrganization('Initech');
    $first = rosterColleague($org->id, MembershipRole::Owner, 'first@initech.test');
    $second = rosterColleague($org->id, MembershipRole::Member, 'second@initech.test');
    $staff = app(Roles::class)->define(null, 'Vendor support', tenantAssignable: false);
    $key = app(EnvironmentApiKeys::class)->issue($tenant['environment']->id, 'People worker', ['members:write', 'roles:write', 'organizations:write'])->plaintext;

    // A key may re-role an owner (from outside, there is no "admin touching an owner"), grant
    // a staff role inside one organization, and reassign ownership with no outgoing owner.
    $this->withToken($key)->putJson("/api/v1/organizations/{$org->id}/members/{$second}/roles/{$staff->id}")->assertOk();
    $this->withToken($key)->postJson("/api/v1/organizations/{$org->id}/transfer-ownership", ['user_id' => $second])->assertOk();

    expect(app(Memberships::class)->owners($org->id))->toBe([$second])
        ->and(app(Memberships::class)->of($org->id, $first)?->role)->toBe(MembershipRole::Admin)
        ->and(RoleAssignment::query()->where('user_id', $second)->pluck('role_id')->all())->toBe([$staff->id]);

    $this->withToken($key)->patchJson("/api/v1/organizations/{$org->id}/members/{$second}", ['role' => 'member'])
        ->assertConflict()
        ->assertJsonPath('error', 'last_owner');

    // And the environment console's administrator, from above every organization.
    actAsEnvironmentAdmin($tenant['subjectId'], $tenant['environment']->id);

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.members.transfer-ownership', [$org->id, $first]))
        ->assertSessionHasNoErrors();

    expect(app(Memberships::class)->owners($org->id))->toBe([$first])
        ->and(rosterAudit('organization.ownership_transferred')?->actor_id)->toBe($tenant['subjectId']);
});

/*
|--------------------------------------------------------------------------
| Leaving: the person's own act, on the account plane
|--------------------------------------------------------------------------
*/

it('leaves one of your own organizations over the account API, and nobody else\'s', function (): void {
    $acme = rosterOrganization('Acme');
    $globex = rosterOrganization('Globex');
    $me = rosterColleague($acme->id, MembershipRole::Member, 'me@acme.test');
    rosterColleague($acme->id, MembershipRole::Owner, 'boss@acme.test');
    $loner = rosterColleague($globex->id, MembershipRole::Owner, 'loner@globex.test');

    FakeDelegatedTokens::install()
        ->person('leave-token', $me, ['account:organizations:write'])
        ->person('narrow-token', $me, ['account:profile:write'])
        ->person('loner-token', $loner, ['account:organizations:write']);

    // Without the scope: nothing.
    $this->withToken('narrow-token')->postJson("/api/v1/me/organizations/{$acme->id}/leave")->assertForbidden();
    // An organization they are not in: not found, never refused.
    $this->withToken('leave-token')->postJson("/api/v1/me/organizations/{$globex->id}/leave")->assertNotFound();

    $this->withToken('leave-token')->postJson("/api/v1/me/organizations/{$acme->id}/leave")->assertNoContent();
    // Gone: asking twice is no second act.
    $this->withToken('leave-token')->postJson("/api/v1/me/organizations/{$acme->id}/leave")->assertNotFound();

    $entry = AuditEntry::query()->where('action', 'organization.member_removed')->where('scope', $acme->id)->sole();

    expect(app(Memberships::class)->of($acme->id, $me))->toBeNull()
        ->and($entry->actor_id)->toBe($me)
        ->and($entry->context['reason'] ?? null)->toBe('left');

    // The last owner is told how to get out instead.
    $this->withToken('loner-token')->postJson("/api/v1/me/organizations/{$globex->id}/leave")
        ->assertConflict()
        ->assertJsonPath('error', 'last_owner');

    expect(app(Memberships::class)->of($globex->id, $loner)?->role)->toBe(MembershipRole::Owner);
});

it('leaves from the People page through the same action', function (): void {
    [$meId, $org] = actingAsRole(MembershipRole::Admin);
    rosterColleague($org->id, MembershipRole::Owner, 'boss@acme.test');

    test()->from(route('directory.members'))->post(route('directory.members.leave'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'You left Acme. You are not a member of any other organization here, so you have been signed out.');

    expect(app(Memberships::class)->of($org->id, $meId))->toBeNull()
        ->and(AuditEntry::query()->where('action', 'organization.member_removed')->where('scope', $org->id)->sole()->actor_id)->toBe($meId);
});
