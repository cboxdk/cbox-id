<?php

declare(strict_types=1);

use App\Models\InvitationRoleGrant;
use App\Platform\GrantAccessRole;
use App\Platform\OrgAccessRoles;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Exceptions\RoleNotTenantAssignable;
use Cbox\Id\AccessControl\Models\GroupRoleMapping;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Models\DirectoryGroup;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Invitation;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| Staff roles stay off the tenant plane
|--------------------------------------------------------------------------
| A role marked `tenant_assignable: false` is the app vendor's own — support, back office —
| and usually carries rights across every customer. An organization's administrator
| handing it to one of their own people is a privilege escalation out of their tenancy.
| The People page, invitations and directory group mappings are the tenant plane; the
| environment console is where staff rights are granted.
*/

beforeEach(function (): void {
    installedDeployment();
});

/** A staff-only role: environment-wide, which is what makes it reachable at all. */
function staffRole(string $name = 'Vendor support'): Role
{
    return app(Roles::class)->define(null, $name, tenantAssignable: false);
}

/** @return list<string> */
function tenantPickerRoleIds(): array
{
    return collect((array) test()->get(route('directory.members'))->assertOk()->inertiaProps('accessRoles'))
        ->pluck('id')
        ->all();
}

function holds(string $organizationId, string $userId, string $roleId): bool
{
    return RoleAssignment::query()
        ->where('organization_id', $organizationId)
        ->where('user_id', $userId)
        ->where('role_id', $roleId)
        ->exists();
}

it('does not offer a staff role on the People page, and offers the shared ones beside it', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $staff = staffRole();
    $shared = app(Roles::class)->define(null, 'Approver');
    $own = app(Roles::class)->define($org->id, 'Editor');

    $offered = tenantPickerRoleIds();

    expect($offered)->not->toContain($staff->id)
        ->and($offered)->toContain($own->id)
        ->and($offered)->toContain($shared->id);
})->group('security');

it('refuses a staff role posted to the People page by id', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $member = app(Subjects::class)->create('dana@acme.test', 'Dana');
    app(Memberships::class)->add($org->id, $member->id, MembershipRole::Member);
    $staff = staffRole();

    // The picker never drew it; the id is still POSTable — and the answer is a refusal
    // the page shows, not a redirect that reads as done.
    setDirectoryAccessRole($member->id, $staff->id, true)
        ->assertRedirect(route('directory.members'))
        ->assertSessionHasErrors(['role' => OrgAccessRoles::NOT_OFFERED]);

    expect(holds($org->id, $member->id, $staff->id))->toBeFalse();

    // The People page reads that error bag into its alert.
    test()->get(route('directory.members'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('errors.role', OrgAccessRoles::NOT_OFFERED));
})->group('security');

it('answers a staff role and a role that does not exist with the same sentence', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $member = app(Subjects::class)->create('dana@acme.test', 'Dana');
    app(Memberships::class)->add($org->id, $member->id, MembershipRole::Member);

    // Telling the two apart would let a tenant administrator map the vendor's staff roles
    // one id at a time; the framework keeps the difference in its exception, for the logs.
    setDirectoryAccessRole($member->id, staffRole()->id, true)->assertSessionHasErrors(['role' => OrgAccessRoles::NOT_OFFERED]);
    setDirectoryAccessRole($member->id, 'no-such-role', true)->assertSessionHasErrors(['role' => OrgAccessRoles::NOT_OFFERED]);
})->group('security');

it('refuses a staff role at the grant itself, with the framework\'s reason', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $member = app(Subjects::class)->create('dana@acme.test', 'Dana');
    app(Memberships::class)->add($org->id, $member->id, MembershipRole::Member);
    $staff = staffRole();

    // The guard behind the picker: a new tenant-plane surface that forgets to filter its
    // list still cannot write the grant.
    expect(fn () => app(GrantAccessRole::class)->grantAsTenant($org->id, $member->id, $staff->id))
        ->toThrow(RoleNotTenantAssignable::class, "Role [{$staff->id}] cannot be granted from the organization plane.");

    expect(holds($org->id, $member->id, $staff->id))->toBeFalse();
})->group('security');

it('lets an environment administrator grant a staff role inside one organization', function (): void {
    crudSetup();
    $user = app(Subjects::class)->create('lead@vendor.example', 'Support lead');
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Customer', slug: 'customer-staff'));
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);
    $staff = staffRole();

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.members.access', [$org->id, $user->id]), [
            'role' => $staff->id,
            'granted' => true,
        ])
        ->assertSessionHasNoErrors();

    expect(holds($org->id, $user->id, $staff->id))->toBeTrue();
})->group('security');

it('does not park a staff role on an invitation, from either console', function (): void {
    Mail::fake();
    [, $org] = actingAsRole(MembershipRole::Owner);
    $staff = staffRole();
    $own = app(Roles::class)->define($org->id, 'Editor');

    // Refused, not filtered: an invitation sent without the role the administrator asked
    // for, under "Invitation sent", is one they believe carries it.
    inviteToDirectory(['accessRoles' => [$staff->id, $own->id]])
        ->assertSessionHasErrors(['accessRoles' => OrgAccessRoles::NOT_OFFERED])
        ->assertSessionMissing('status');

    expect(InvitationRoleGrant::query()->where('organization_id', $org->id)->exists())->toBeFalse()
        ->and(Invitation::query()->where('organization_id', $org->id)->exists())->toBeFalse();
    Mail::assertNothingSent();

    // The same invitation without it goes, carrying exactly what was asked.
    inviteToDirectory(['accessRoles' => [$own->id]])->assertSessionHasNoErrors();

    expect(InvitationRoleGrant::query()->where('organization_id', $org->id)->pluck('role_id')->all())->toBe([$own->id]);
})->group('security');

it('refuses a staff role on an invitation from the environment console too', function (): void {
    Mail::fake();
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Customer', slug: 'customer-invite-staff'));
    $staff = staffRole();

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.invitations.store', $org->id), [
            'email' => 'newbie@customer.test',
            'role' => 'member',
            'accessRoles' => [$staff->id],
        ])
        ->assertSessionHasErrors(['accessRoles' => OrgAccessRoles::NOT_OFFERED]);

    expect(Invitation::query()->where('organization_id', $org->id)->exists())->toBeFalse();
})->group('security');

it('withholds a parked role that became staff-only before the invitation was accepted', function (): void {
    Mail::fake();
    [, $org] = actingAsRole(MembershipRole::Owner);
    $role = app(Roles::class)->define(null, 'Back office');

    $pending = app(Invitations::class)->invite($org->id, 'newbie@acme.test', MembershipRole::Member);
    InvitationRoleGrant::query()->create([
        'invitation_id' => $pending->invitation->id,
        'organization_id' => $org->id,
        'email' => 'newbie@acme.test',
        'role_id' => $role->id,
    ]);

    // The app's next manifest marks it staff-only.
    Role::query()->whereKey($role->id)->update(['tenant_assignable' => false]);

    $this->post('/invitations/'.$pending->token.'/accept')->assertRedirect();

    $subject = app(Subjects::class)->findByEmail('newbie@acme.test');

    expect($subject)->not->toBeNull()
        ->and(holds($org->id, (string) $subject?->id, $role->id))->toBeFalse();
})->group('security');

it('offers the environment console\'s invite form only the roles a tenant could hand out', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Customer', slug: 'customer-invite'));
    $staff = staffRole();
    $shared = app(Roles::class)->define(null, 'Approver');

    $page = test()->get(route('environment.organizations.show', $org->id))->assertOk();

    $grantable = collect((array) $page->inertiaProps('accessRoles'))->pluck('id')->all();
    $invitable = collect((array) $page->inertiaProps('inviteAccessRoles'))->pluck('id')->all();

    // Granted on a member directly: yes. Carried by an invitation: no.
    expect($grantable)->toContain($staff->id)
        ->and($invitable)->not->toContain($staff->id)
        ->and($invitable)->toContain($shared->id);
});

it('marks a staff role as staff-only wherever the environment console offers one', function (): void {
    crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization(name: 'Customer', slug: 'customer-staff-tag'));
    $staff = staffRole();
    $shared = app(Roles::class)->define(null, 'Approver');
    $user = app(Subjects::class)->create('grace@customer.test', 'Grace');
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);

    $tags = fn (array $roles): array => collect($roles)->pluck('staffOnly', 'id')->all();

    // The organization's page: its add-member picker and every member's roles.
    $page = test()->get(route('environment.organizations.show', $org->id))->assertOk();
    expect($tags((array) $page->inertiaProps('accessRoles')))->toMatchArray([$staff->id => true, $shared->id => false]);

    // A user's page: each membership's roles, and the add-to-organization picker.
    $page = test()->get(route('environment.users.show', ['user' => $user->id, 'org' => $org->id]))->assertOk();
    expect($tags((array) $page->inertiaProps('memberships.0.accessRoles')))->toMatchArray([$staff->id => true, $shared->id => false]);

    // The tenant plane never lists it at all (the People page test above), so there is
    // nothing there to tag.
});

it('does not offer a staff role for a directory group, and refuses it in words when posted', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $directory = app(Directories::class)->register($org->id, 'Okta')->directory;
    $group = DirectoryGroup::query()->create([
        'directory_id' => $directory->id,
        'external_id' => 'grp-support',
        'display_name' => 'Support',
    ]);
    $staff = staffRole();

    // Who is in a group is the customer's IdP's call, so a mapping is a tenant's grant.
    $offered = collect((array) $this->get(route('directories.show', $directory->id))->assertOk()->inertiaProps('roles'))
        ->pluck('id')
        ->all();

    expect($offered)->not->toContain($staff->id);

    $this->from(route('directories.show', $directory->id))
        ->post(route('directories.map', $directory->id), [
            'group' => $group->id,
            'role' => $staff->id,
            'mapped' => true,
        ])
        ->assertSessionHasErrors(['role' => 'That role cannot be given to a directory group here.']);

    expect(GroupRoleMapping::query()->exists())->toBeFalse();
})->group('security');
