<?php

declare(strict_types=1);

use App\Models\InvitationRoleGrant;
use App\Platform\CurrentUser;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\AccessControl\Models\RoleAssignment;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * @return array{0: string, 1: string} [organization id, member's user id]
 */
function sodEnforcementOrg(): array
{
    $admin = app(Subjects::class)->create('admin@acme.test', 'Admin', 'supersecret123');
    $member = app(Subjects::class)->create('member@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-sod-enforce'));
    app(Memberships::class)->add($org->id, $admin->id, 'owner');
    app(Memberships::class)->add($org->id, $member->id, 'member');
    $session = app(SessionManager::class)->start($admin->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($admin, $session, $org, MembershipRole::Owner);

    return [$org->id, $member->id];
}

/**
 * @return array{0: Role, 1: Role}
 */
function sodEnforcementRoles(string $orgId): array
{
    return [
        app(Roles::class)->define($orgId, 'Create purchase order'),
        app(Roles::class)->define($orgId, 'Approve payment'),
    ];
}

/**
 * The console shipped the whole SoD surface — define a policy here, see violations
 * there — and never called the pre-grant gate the framework handed it. So an admin
 * could create on the Members page exactly the toxic combination the Governance page
 * reports, one screen contradicting the other.
 */
it('refuses a role grant that would complete a toxic combination, and names both roles', function (): void {
    [$orgId, $memberId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);
    app(Roles::class)->assign($orgId, $memberId, $createPo->id);

    Volt::test('members')
        ->call('toggleRole', $memberId, $approvePay->id)
        ->assertDispatched('toast', message: 'Blocked by "PO vs payment": "Approve payment" cannot be held together with "Create purchase order".', severity: 'error');

    expect(RoleAssignment::query()->where('user_id', $memberId)->where('role_id', $approvePay->id)->exists())->toBeFalse();
});

it('still allows a grant with no conflict', function (): void {
    [$orgId, $memberId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);

    Volt::test('members')->call('toggleRole', $memberId, $approvePay->id);

    expect(RoleAssignment::query()->where('user_id', $memberId)->where('role_id', $approvePay->id)->exists())->toBeTrue();
});

it('still allows REVOKING a role even where a policy applies', function (): void {
    [$orgId, $memberId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);
    app(Roles::class)->assign($orgId, $memberId, $createPo->id);

    // Taking a role AWAY can never complete a forbidden combination.
    Volt::test('members')->call('toggleRole', $memberId, $createPo->id);

    expect(RoleAssignment::query()->where('user_id', $memberId)->where('role_id', $createPo->id)->exists())->toBeFalse();
});

it('enforces an ENVIRONMENT-WIDE policy on a grant too', function (): void {
    [$orgId, $memberId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy(null, 'Env-wide PO vs payment', [$createPo->id, $approvePay->id]);
    app(Roles::class)->assign($orgId, $memberId, $createPo->id);

    Volt::test('members')->call('toggleRole', $memberId, $approvePay->id);

    expect(RoleAssignment::query()->where('user_id', $memberId)->where('role_id', $approvePay->id)->exists())->toBeFalse();
});

/**
 * A parked invite grant is a grant, just deferred. The invitee holds nothing yet, so
 * per-role evaluation sees no conflict — it is the SET chosen for them that violates,
 * and by acceptance time the only place left to refuse is a redirect-only controller
 * with nowhere to say why.
 */
it('refuses an invitation whose chosen access roles are themselves a toxic combination', function (): void {
    Mail::fake();
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);

    Volt::test('members')
        ->set('inviteEmail', 'newbie@acme.test')
        ->set('inviteRole', 'member')
        ->set('inviteAccessRoles', [$createPo->id, $approvePay->id])
        ->call('invite')
        ->assertHasErrors('inviteAccessRoles');

    expect(InvitationRoleGrant::query()->where('email', 'newbie@acme.test')->exists())->toBeFalse();
    Mail::assertNothingSent();
});

it('accepts an invitation whose chosen access roles do not conflict', function (): void {
    Mail::fake();
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);

    Volt::test('members')
        ->set('inviteEmail', 'newbie@acme.test')
        ->set('inviteRole', 'member')
        ->set('inviteAccessRoles', [$createPo->id])
        ->call('invite')
        ->assertHasNoErrors();

    expect(InvitationRoleGrant::query()->where('email', 'newbie@acme.test')->count())->toBe(1);
});

/**
 * Defence in depth: a policy defined between the invite and its acceptance must still
 * be honoured. The person joins; only the conflicting grant is withheld.
 */
it('withholds a parked grant that has become conflicting by the time it is accepted', function (): void {
    Mail::fake();
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    $invitee = app(Subjects::class)->create('later@acme.test', 'Later', 'supersecret123');
    $invitation = app(Invitations::class)->invite($orgId, 'later@acme.test', 'member');

    InvitationRoleGrant::query()->create(['organization_id' => $orgId, 'email' => 'later@acme.test', 'role_id' => $createPo->id]);
    InvitationRoleGrant::query()->create(['organization_id' => $orgId, 'email' => 'later@acme.test', 'role_id' => $approvePay->id]);

    // The rule appears AFTER the invite was sent.
    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs payment', [$createPo->id, $approvePay->id]);

    $this->get(route('invitation.accept', $invitation->token))->assertRedirect(route('dashboard'));

    // They joined, and hold exactly one of the two — never the forbidden pair.
    expect(app(Memberships::class)->of($orgId, $invitee->id))->not->toBeNull()
        ->and(RoleAssignment::query()->where('user_id', $invitee->id)->count())->toBe(1);
});

/**
 * An environment-wide policy is the control plane's own rule and binds every tenant.
 * An org admin who could switch it off could then grant themselves the very pair it
 * forbids — a complete bypass of the control, from inside the org console.
 */
it('does not let an org admin deactivate an environment-wide policy', function (): void {
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    $envWide = app(SegregationOfDuties::class)->definePolicy(null, 'Env-wide PO vs payment', [$createPo->id, $approvePay->id]);

    // A forged Livewire call, not just a hidden button: the id resolves to nothing
    // within the org's own scope, so it 404s (deny-by-default) instead of toggling.
    expect(fn () => Volt::test('sod-policies')->call('toggle', $envWide->id))
        ->toThrow(ModelNotFoundException::class);

    expect(SodPolicy::query()->whereKey($envWide->id)->value('active'))->toBeTrue();
});

it('shows an environment-wide policy as read-only rather than hiding it', function (): void {
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    app(SegregationOfDuties::class)->definePolicy(null, 'Env-wide PO vs payment', [$createPo->id, $approvePay->id]);

    // The org must SEE what constrains it; it just cannot change it.
    Volt::test('sod-policies')
        ->assertSee('Env-wide PO vs payment')
        ->assertSee('Environment-wide')
        ->assertSee('Managed for the environment')
        ->assertDontSee('Deactivate');
});

it('still lets an org admin toggle its own policy', function (): void {
    [$orgId] = sodEnforcementOrg();
    [$createPo, $approvePay] = sodEnforcementRoles($orgId);

    $own = app(SegregationOfDuties::class)->definePolicy($orgId, 'Our PO vs payment', [$createPo->id, $approvePay->id]);

    Volt::test('sod-policies')->call('toggle', $own->id);

    expect(SodPolicy::query()->whereKey($own->id)->value('active'))->toBeFalse();
});
