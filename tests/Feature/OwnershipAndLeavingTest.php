<?php

declare(strict_types=1);

use App\Platform\Membership\MembershipLifecycle;
use App\Platform\Membership\MembershipRefusalReason;
use App\Platform\Membership\MembershipRefused;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Inertia\Testing\AssertableInertia;

/**
 * ONE OWNER, MOVED BY TRANSFER — and the two ways out of an organization.
 *
 * The People page offered "Owner" in its role picker, so an owner could mint more owners,
 * each able to demote the others, while the framework's rule (and the customer console) was
 * that ownership is transferred. The picker no longer offers it; "Transfer ownership" moves
 * it. And a member could not leave on their own ("You cannot remove yourself"), nor could an
 * owner close their own organization — only an environment administrator could.
 */
beforeEach(function (): void {
    installedDeployment();
});

/** A member of the acting organization, with a name to find them by. */
function colleague(string $organizationId, MembershipRole $role, string $email): string
{
    $subject = app(Subjects::class)->create($email, ucfirst(strtok($email, '@') ?: 'Colleague'));
    app(Memberships::class)->add($organizationId, $subject->id, $role);

    return $subject->id;
}

/** @return list<string> the owners' subject ids */
function ownersOf(string $organizationId): array
{
    return app(Memberships::class)->forOrganization($organizationId)
        ->filter(fn (Membership $m): bool => $m->role === MembershipRole::Owner)
        ->pluck('user_id')
        ->values()
        ->all();
}

it('transfers ownership: the new owner is promoted and the old one stays on as an admin', function (): void {
    [$ownerId, $org] = actingAsRole(MembershipRole::Owner);
    $danaId = colleague($org->id, MembershipRole::Member, 'dana@acme.test');

    test()->from(route('directory.members'))
        ->post(route('directory.members.transfer-ownership', $danaId))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Ownership transferred to Dana. You are now an admin.');

    expect(ownersOf($org->id))->toBe([$danaId])
        ->and(app(Memberships::class)->of($org->id, $ownerId)?->role)->toBe(MembershipRole::Admin)
        // Once: the framework's transfer records it, and the console no longer adds its own.
        ->and(AuditEntry::query()->where('action', 'organization.ownership_transferred')->where('scope', $org->id)->count())->toBe(1);
});

it('will not hand the organization to a member who is suspended', function (): void {
    [$ownerId, $org] = actingAsRole(MembershipRole::Owner);
    $danaId = colleague($org->id, MembershipRole::Member, 'dana@acme.test');
    app(TenantContext::class)->withoutScope(fn () => Membership::query()
        ->where('organization_id', $org->id)
        ->where('user_id', $danaId)
        ->update(['status' => MembershipStatus::Suspended->value]));

    $reason = null;

    try {
        app(MembershipLifecycle::class)->transferOwnership($org->id, $danaId, $ownerId, $ownerId);
    } catch (MembershipRefused $e) {
        $reason = $e->reason;
    }

    expect($reason)->toBe(MembershipRefusalReason::NotActive)
        ->and(ownersOf($org->id))->toBe([$ownerId]);
});

it('lets only the owner transfer ownership', function (): void {
    [, $org] = actingAsRole(MembershipRole::Admin);
    $danaId = colleague($org->id, MembershipRole::Member, 'dana@acme.test');

    test()->from(route('directory.members'))
        ->post(route('directory.members.transfer-ownership', $danaId))
        ->assertForbidden();

    expect(app(Memberships::class)->of($org->id, $danaId)?->role)->toBe(MembershipRole::Member);
});

it('will not transfer to somebody who is not a member', function (): void {
    [$ownerId, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-transfer'));
    $strangerId = colleague($other->id, MembershipRole::Member, 'stranger@globex.test');

    test()->from(route('directory.members'))
        ->post(route('directory.members.transfer-ownership', $strangerId))
        ->assertSessionHasErrors(['member' => 'That person is not a member of this organization.']);

    expect(ownersOf($org->id))->toBe([$ownerId])
        ->and(app(Memberships::class)->of($other->id, $strangerId)?->role)->toBe(MembershipRole::Member);
});

it('offers no Owner in any picker, and draws the owner\'s row without one', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    $this->get(route('directory.members'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/directory-members')
            ->where('roleOptions', fn ($options): bool => collect($options)->pluck('value')->all() === ['admin', 'member'])
            // The roster's copy names Owner so an owner's row can say what it holds —
            // disabled, so it is never a choice.
            ->where('rosterRoleOptions.0.value', 'owner')
            ->where('rosterRoleOptions.0.disabled', true));
});

it('lets a member leave, and signs them out when it was their only organization', function (): void {
    [, $org] = actingAsRole(MembershipRole::Member);
    $meId = (string) app(Subjects::class)->findByEmail('member@acme.test')?->id;
    colleague($org->id, MembershipRole::Owner, 'boss@acme.test');

    test()->from(route('directory.members'))
        ->post(route('directory.members.leave'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'You left Acme. You are not a member of any other organization here, so you have been signed out.');

    // One entry, the framework's: a removal the member made themselves, attributed to them.
    // The console wrote a second `organization.member_left` beside it until the framework's
    // leave() existed.
    $entry = AuditEntry::query()->where('action', 'organization.member_removed')->where('scope', $org->id)->sole();

    expect(app(Memberships::class)->of($org->id, $meId))->toBeNull()
        ->and(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse()
        ->and($entry->context['reason'] ?? null)->toBe('left')
        ->and($entry->actor_id)->toBe($meId)
        ->and(AuditEntry::query()->where('action', 'organization.member_left')->exists())->toBeFalse();
});

it('moves a leaver to their next organization when they have one', function (): void {
    [$meId, $org] = actingAsRole(MembershipRole::Member);
    colleague($org->id, MembershipRole::Owner, 'boss@acme.test');
    $next = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-next'));
    app(Memberships::class)->add($next->id, $meId, MembershipRole::Member);

    test()->from(route('directory.members'))
        ->post(route('directory.members.leave'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'You left Acme.');

    expect(session(PlatformAuth::ORG_KEY))->toBe($next->id);
});

it('refuses to let the last owner leave, and says how to get out', function (): void {
    [$meId, $org] = actingAsRole(MembershipRole::Owner);

    test()->from(route('directory.members'))
        ->post(route('directory.members.leave'))
        ->assertSessionHasErrors(['member' => 'You are the only owner. Transfer ownership to someone else first — or delete the organization.']);

    expect(app(Memberships::class)->of($org->id, $meId)?->role)->toBe(MembershipRole::Owner);
});

it('refuses with the REASON, so a test cannot pass on a different refusal', function (): void {
    [$meId, $org] = actingAsRole(MembershipRole::Owner);

    $reason = null;

    try {
        app(MembershipLifecycle::class)->leave($org->id, $meId);
    } catch (MembershipRefused $e) {
        $reason = $e->reason;
    }

    expect($reason)->toBe(MembershipRefusalReason::LastOwner);
});

it('lets an environment administrator make somebody the owner of an organization that has none', function (): void {
    craftedEnvAdmin();
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-no-owner'));
    $erinId = colleague($org->id, MembershipRole::Member, 'erin@tenant.test');

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.members.transfer-ownership', [$org->id, $erinId]))
        ->assertSessionHas('status', 'Ownership transferred.');

    expect(ownersOf($org->id))->toBe([$erinId]);
});

it('steps every current owner down when an environment administrator re-assigns ownership', function (): void {
    craftedEnvAdmin();
    $org = app(Organizations::class)->create(new NewOrganization('Tenant', 'tenant-two-owners'));
    // Two owners — the state the People page could produce before ownership was a transfer.
    $aId = colleague($org->id, MembershipRole::Owner, 'a@tenant.test');
    $bId = colleague($org->id, MembershipRole::Owner, 'b@tenant.test');
    $cId = colleague($org->id, MembershipRole::Member, 'c@tenant.test');

    test()->from(route('environment.organizations.show', $org->id))
        ->post(route('environment.organizations.members.transfer-ownership', [$org->id, $cId]))
        ->assertSessionHasNoErrors();

    expect(ownersOf($org->id))->toBe([$cId])
        ->and(app(Memberships::class)->of($org->id, $aId)?->role)->toBe(MembershipRole::Admin)
        ->and(app(Memberships::class)->of($org->id, $bId)?->role)->toBe(MembershipRole::Admin);
});

it('lets the owner delete their own organization, behind a fresh password and its typed name', function (): void {
    [$meId, $org] = actingAsRole(MembershipRole::Owner);

    // Offered on the page to the owner…
    $this->get(route('settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('closeOrganizationHref', route('settings.organization.destroy')));

    // …but not without a fresh password.
    test()->from(route('settings'))
        ->delete(route('settings.organization.destroy'), ['name' => 'Acme'])
        ->assertRedirect(route('sudo'));

    app(Sudo::class)->confirm();

    // A near miss is a miss.
    test()->from(route('settings'))
        ->delete(route('settings.organization.destroy'), ['name' => 'acme'])
        ->assertSessionHasErrors(['name' => 'Type the organization\'s name exactly as shown to confirm.']);

    expect(app(Organizations::class)->find($org->id)?->status)->toBe(OrganizationStatus::Active);

    test()->from(route('settings'))
        ->delete(route('settings.organization.destroy'), ['name' => 'Acme'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Acme has been deleted. You are not a member of any other organization here, so you have been signed out.');

    expect(app(Organizations::class)->find($org->id)?->status)->toBe(OrganizationStatus::Deleted)
        ->and(app(Memberships::class)->of($org->id, $meId))->not->toBeNull('archived, not erased');
});

it('does not let an admin delete the organization', function (): void {
    [, $org] = actingAsRole(MembershipRole::Admin);
    colleague($org->id, MembershipRole::Owner, 'boss@acme.test');
    app(Sudo::class)->confirm();

    $this->get(route('settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('closeOrganizationHref', null));

    test()->from(route('settings'))
        ->delete(route('settings.organization.destroy'), ['name' => 'Acme'])
        ->assertSessionHasErrors(['name' => 'Only the owner can do that.']);

    expect(app(Organizations::class)->find($org->id)?->status)->toBe(OrganizationStatus::Active);
});

it('does not accept an invitation that would mint a second owner from the People page', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    inviteToDirectory(['role' => 'owner'])->assertSessionHasErrors(['role' => 'Choose one of: Admin, Member.']);

    expect(app(Invitations::class)->pending($org->id))->toHaveCount(0);
});
