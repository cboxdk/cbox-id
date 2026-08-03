<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/** Sign in as the owner of a fresh org on the org (member-facing) console. */
function craftedOrgOwner(string $slug): string
{
    $subject = app(Subjects::class)->create($slug.'@acme.test', 'Owner', 'a-perfectly-long-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, app(Organizations::class)->find($org->id), MembershipRole::Owner);

    return $org->id;
}

/** Provision an account + environment and act as its env admin (the control plane). */
function craftedEnvAdmin(): void
{
    platformRootEnvironment();
    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->member, $result->environment->id);
}

/**
 * A public Livewire prop is attacker-controlled: the wire request carries the whole
 * component state, so a `<select>` constrains a browser and nothing else. Where such a
 * prop reached an `Enum::from()` unvalidated, a crafted request threw ValueError and the
 * console answered 500 — a refusal dressed as a crash, and a needlessly loud one.
 *
 * These drive the ACTION rather than asserting on the rules array, so a rule that is
 * present but not reached still fails here.
 */
it('refuses a crafted enum on the sign-in rules form instead of throwing', function (): void {
    platformRootEnvironment();
    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->member, $result->environment->id);

    Volt::test('environment.auth-policy')
        ->set('mfa', 'not-a-requirement')
        ->set('sso', 'also-not-real')
        ->call('save')
        ->assertHasErrors(['mfa', 'sso']);
});

it('refuses a crafted revocation scope on the admin set-password panel', function (): void {
    platformRootEnvironment();
    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->member, $result->environment->id);

    $userId = app(Subjects::class)->create('dana@acme.example', 'Dana', 'the-original-passphrase')->id;

    Volt::test('environment.users.show', ['user' => $userId])
        ->set('pwPassword', 'a-perfectly-long-passphrase')
        ->set('pwReason', 'Locked out')
        ->set('pwRevoke', 'everything-everywhere')
        ->call('setPassword')
        ->assertHasErrors('pwRevoke');

    // …and the credential is untouched by the refused call.
    expect(app(Subjects::class)->verifyPassword($userId, 'the-original-passphrase'))->toBeTrue();
});

it('refuses a crafted hook point instead of throwing', function (): void {
    $subject = app(Subjects::class)->create('admin@acme.test', 'Admin', 'a-perfectly-long-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-hooks'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Admin);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, app(Organizations::class)->find($org->id), MembershipRole::Admin);

    Volt::test('console.hooks.create')
        ->set('hook', 'definitely_not_a_hook_point')
        ->set('url', 'https://example.test/hook')
        ->call('register')
        ->assertHasErrors('hook');
});

/**
 * Account-member emails are unique across EVERY account, so the old "that email already
 * belongs to a member" told an admin of one account whether an address belonged to
 * another. Both cases now answer identically.
 */
it('does not tell one account whether an email belongs to another', function (): void {
    platformRootEnvironment();

    $mine = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Rival',
        ownerEmail: 'owner@rival.example',
        ownerName: 'Rival Owner',
        ownerPassword: 'another-strong-passphrase',
    ));

    signInAsMember($mine->member);

    $probeOther = Volt::test('workspace.members')
        ->set('inviteEmail', 'owner@rival.example')
        ->set('inviteRole', 'admin')
        ->call('invite')
        ->assertHasErrors('inviteEmail');

    $probeOwn = Volt::test('workspace.members')
        ->set('inviteEmail', 'owner@acme.example')
        ->set('inviteRole', 'admin')
        ->call('invite')
        ->assertHasErrors('inviteEmail');

    // The distinguishing detail is what mattered: identical wording either way.
    expect($probeOther->errors()->first('inviteEmail'))
        ->toBe($probeOwn->errors()->first('inviteEmail'))
        ->and($probeOther->errors()->first('inviteEmail'))->not->toContain('member');
});

/**
 * Membership roles reach {@see Memberships::add()}, `changeRole()` and
 * {@see Invitations::invite()}, which take a {@see MembershipRole} — so the parse has to
 * happen at the HTTP edge. Wrapping the prop in `MembershipRole::from()` inside the
 * component would only relocate the `ValueError`: the console would 500 on a crafted
 * payload instead of answering with a field error, which is the fail-open the framework's
 * enum-typed contract exists to close.
 *
 * Two shapes of crafted value matter, and both are covered below:
 *  - a value that is no case of the enum at all ('archduke'), and
 *  - a real enum case this console deliberately does not assign ('viewer') — the check
 *    is the assignable set, not merely `tryFrom() !== null`.
 */
it('refuses a crafted invite role on the org members form instead of throwing', function (): void {
    Mail::fake();
    $orgId = craftedOrgOwner('acme-invite-role');

    foreach (['archduke', 'viewer', ''] as $crafted) {
        $probe = Volt::test('members')
            ->set('inviteEmail', 'joiner@acme.test')
            ->set('inviteRole', $crafted)
            ->call('invite')
            ->assertHasErrors('inviteRole');

        // The message names what IS accepted, rather than "the selected value is invalid".
        expect($probe->errors()->first('inviteRole'))->toBe('Choose one of: Member, Admin, Owner.');
    }

    expect(app(Invitations::class)->pending($orgId))->toHaveCount(0);
    Mail::assertNothingSent();
});

it('refuses a crafted role on the org members roster select without changing it', function (): void {
    $orgId = craftedOrgOwner('acme-set-role');
    $target = app(Subjects::class)->create('target@acme.test', 'Target');
    app(Memberships::class)->add($orgId, $target->id, MembershipRole::Member);

    // setRole is invoked from JS with the <select>'s value — no form field to report
    // into, so the refusal is a no-op rather than an error bag entry. It must still not
    // be a 500, and must not fall through to a default role.
    foreach (['archduke', 'viewer', 'OWNER'] as $crafted) {
        Volt::test('members')->call('setRole', $target->id, $crafted)->assertOk();
        expect(app(Memberships::class)->of($orgId, $target->id)?->role)->toBe(MembershipRole::Member);
    }
});

it('refuses a crafted role on the env-admin organization member forms', function (): void {
    Mail::fake();
    craftedEnvAdmin();

    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant', slug: 'tenant-crafted'));
    $user = app(Subjects::class)->create('dave@acme.example', 'Dave');

    foreach (['archduke', 'viewer'] as $crafted) {
        $add = Volt::test('environment.organizations.show', ['organization' => $org->id])
            ->set('memberEmail', 'dave@acme.example')
            ->set('memberRole', $crafted)
            ->call('addMember')
            ->assertHasErrors('memberRole');

        expect($add->errors()->first('memberRole'))->toBe('Choose one of: Member, Admin, Owner.');

        $invite = Volt::test('environment.organizations.show', ['organization' => $org->id])
            ->set('inviteEmail', 'newbie@acme.example')
            ->set('inviteRole', $crafted)
            ->call('invite')
            ->assertHasErrors('inviteRole');

        expect($invite->errors()->first('inviteRole'))->toBe('Choose one of: Member, Admin, Owner.');
    }

    expect(app(Memberships::class)->of($org->id, $user->id))->toBeNull()
        ->and(app(Invitations::class)->pending($org->id))->toHaveCount(0);
    Mail::assertNothingSent();

    // …and the JS-invoked roster select refuses without demoting anyone.
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Admin);

    foreach (['archduke', 'viewer'] as $crafted) {
        Volt::test('environment.organizations.show', ['organization' => $org->id])
            ->call('changeMemberRole', $user->id, $crafted)
            ->assertOk();

        expect(app(Memberships::class)->of($org->id, $user->id)?->role)->toBe(MembershipRole::Admin);
    }
});

it('refuses a crafted role on the env-admin user detail page', function (): void {
    craftedEnvAdmin();

    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant', slug: 'tenant-user-crafted'));
    $user = app(Subjects::class)->create('erin@acme.example', 'Erin');

    foreach (['archduke', 'viewer'] as $crafted) {
        $assign = Volt::test('environment.users.show', ['user' => $user->id])
            ->set('assignOrgId', $org->id)
            ->set('assignRole', $crafted)
            ->call('assignOrg')
            ->assertHasErrors('assignRole');

        expect($assign->errors()->first('assignRole'))->toBe('Choose one of: Member, Admin, Owner.');
    }

    expect(app(Memberships::class)->of($org->id, $user->id))->toBeNull();

    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);

    foreach (['archduke', 'viewer'] as $crafted) {
        Volt::test('environment.users.show', ['user' => $user->id])
            ->call('changeMembershipRole', $org->id, $crafted)
            ->assertOk();

        expect(app(Memberships::class)->of($org->id, $user->id)?->role)->toBe(MembershipRole::Member);
    }
});
