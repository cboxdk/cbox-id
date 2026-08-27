<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

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
    // The environment console is `/admin`, which 404s unless the deployment is
    // multi-tenant — the page is reached by REQUEST now rather than driven directly.
    multiTenantDeployment();

    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);
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
    // The environment console is `/admin`, which 404s unless the deployment is
    // multi-tenant — the page is reached by REQUEST now rather than driven directly.
    multiTenantDeployment();

    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $current = (array) test()->get(route('environment.auth-policy'))->assertOk()->inertiaProps('policy');

    test()->from(route('environment.auth-policy'))
        ->put(route('environment.auth-policy.update'), [
            ...$current,
            'mfa' => 'not-a-requirement',
            'sso' => 'also-not-real',
        ])
        ->assertSessionHasErrors(['mfa', 'sso']);
});

it('refuses a crafted revocation scope on the admin set-password panel', function (): void {
    multiTenantDeployment();
    platformRootEnvironment();
    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($result->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($result->environment->id));
    actAsEnvironmentAdmin($result->owner->id, $result->environment->id);

    $userId = app(Subjects::class)->create('dana@acme.example', 'Dana', 'the-original-passphrase')->id;

    // The step-up first, so the refusal under test is the SHAPE check rather than the
    // credential challenge in front of it — a page that answered every request with a
    // step-up would pass this otherwise.
    confirmEnvironmentStepUp();

    setUserPassword($userId, [
        'password' => 'a-perfectly-long-passphrase',
        'reason' => 'Locked out',
        'revoke' => 'everything-everywhere',
    ])->assertSessionHasErrors('revoke');

    // …and the credential is untouched by the refused call.
    expect(app(Subjects::class)->verifyPassword($userId, 'the-original-passphrase'))->toBeTrue();
});

it('refuses a crafted hook point instead of throwing', function (): void {
    $subject = app(Subjects::class)->create('admin@acme.test', 'Admin', 'a-perfectly-long-passphrase');
    // VERIFIED, because that is what an established admin of an established organization
    // IS — the same reasoning `actingAsRole()` states and applies by default. An
    // unverified fixture quietly exercises the unverified-account rules instead of the
    // page under test, and then the fixture gets blamed rather than the rule.
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-hooks'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Admin);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, app(Organizations::class)->find($org->id), MembershipRole::Admin);

    confirmConsoleStepUp();

    // A CRAFTED HOOK POINT. Without the enum rule `HookPoint::from()` throws a ValueError
    // and the console answers 500 instead of refusing the input — the difference between a
    // validated field and a crash reachable by anybody who can post a form.
    registerHook(['point' => 'definitely_not_a_hook_point'])->assertSessionHasErrors('point');

    expect(ExternalActionEndpoint::query()->exists())->toBeFalse();
});

/**
 * THE ORACLE IS GONE RATHER THAN CLOSED, and that is the honest way to record it.
 *
 * Account-member emails were unique across EVERY account, so "that email already belongs to
 * a member" told an admin of one account whether an address belonged to another. The fix
 * then was to answer identically in both cases.
 *
 * A person can now legitimately hold memberships in several organizations — the same human
 * may own one and be a viewer in another — so an address belonging elsewhere is not a
 * reason to refuse anything, and the invitation simply succeeds. There is no question for a
 * probe to ask: the only refusal left is "already a member HERE", which is a fact the
 * roster on the same page already shows them.
 */
it('refuses only an email that is already a member here, and discloses nothing else', function (): void {
    platformRootEnvironment();
    $mine = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Rival',
        ownerEmail: 'owner@rival.example',
        ownerName: 'Rival Owner',
        ownerPassword: 'another-strong-passphrase',
    ));

    signInAsMember($mine->owner->id);

    Mail::fake();

    // Somebody else's owner: a legitimate invitation, accepted without comment. Refusing
    // it would BE the oracle — it would confirm that the address is known to the platform.
    test()->from(route('members'))
        ->post(route('members.invite'), ['email' => 'owner@rival.example', 'role' => 'admin'])
        ->assertSessionHasNoErrors();

    // Somebody already on THIS roster: refused, and the refusal discloses nothing the page
    // is not already showing.
    $probeOwn = test()->from(route('members'))
        ->post(route('members.invite'), ['email' => 'owner@acme.example', 'role' => 'admin'])
        ->assertSessionHasErrors('email');

    expect(session('errors')?->getBag('default')->first('email'))->not->toContain('account');

    /*
     * AND A CRAFTED ROLE IS REFUSED BY NAME. `Invitations::invite()` takes a
     * `MembershipRole`, so an unparseable value has to be turned away at the HTTP edge —
     * parsing it deeper would only move the `ValueError` and answer a crafted payload with
     * a 500 instead of a field error.
     *
     * `owner` rather than a second nonsense string: it is a REAL case of the enum that this
     * form deliberately does not assign, because ownership is transferred by the owner
     * rather than granted by an invitation. The check is the assignable set, not merely
     * `tryFrom() !== null` — and only a real case can tell the two apart.
     */
    foreach (['archduke', 'owner', ''] as $crafted) {
        test()->from(route('members'))
            ->post(route('members.invite'), ['email' => 'joiner@acme.example', 'role' => $crafted])
            ->assertSessionHasErrors('role');
    }

    expect(session('errors')?->getBag('default')->first('role'))
        // The message names what IS accepted, rather than "the selected value is invalid".
        ->toBe('Choose one of: Admin, Developer, Member, Viewer.');
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
        // The message names what IS accepted, rather than "the selected value is invalid".
        inviteToDirectory(['email' => 'joiner@acme.test', 'role' => $crafted])
            ->assertSessionHasErrors(['role' => 'Choose one of: Member, Admin, Owner.']);
    }

    expect(app(Invitations::class)->pending($orgId))->toHaveCount(0);
    Mail::assertNothingSent();
});

it('refuses a crafted role on the org members roster select without changing it', function (): void {
    $orgId = craftedOrgOwner('acme-set-role');
    $target = app(Subjects::class)->create('target@acme.test', 'Target');
    app(Memberships::class)->add($orgId, $target->id, MembershipRole::Member);

    // The roster select is a control with no field of its own, so this used to refuse
    // SILENTLY — the row simply did not change and nothing said why. It is its own request
    // now, so the refusal has somewhere to land and names the choices like every other.
    foreach (['archduke', 'viewer', 'OWNER'] as $crafted) {
        test()->from(route('directory.members'))
            ->patch(route('directory.members.role', $target->id), ['role' => $crafted])
            ->assertSessionHasErrors(['role' => 'Choose one of: Member, Admin, Owner.']);

        expect(app(Memberships::class)->of($orgId, $target->id)?->role)->toBe(MembershipRole::Member);
    }
});

it('refuses a crafted role on the env-admin organization member forms', function (): void {
    Mail::fake();
    craftedEnvAdmin();

    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant', slug: 'tenant-crafted'));
    $user = app(Subjects::class)->create('dave@acme.example', 'Dave');

    foreach (['archduke', 'viewer'] as $crafted) {
        // The refusal NAMES THE CHOICES rather than saying "invalid": whoever hits this
        // legitimately (a stale tab, a renamed role) needs to know what to pick instead.
        addOrganizationMember($org->id, ['email' => 'dave@acme.example', 'role' => $crafted])
            ->assertSessionHasErrors(['role' => 'Choose one of: Member, Admin, Owner.']);

        inviteOrganizationMember($org->id, ['email' => 'newbie@acme.example', 'role' => $crafted])
            ->assertSessionHasErrors(['role' => 'Choose one of: Member, Admin, Owner.']);
    }

    expect(app(Memberships::class)->of($org->id, $user->id))->toBeNull()
        ->and(app(Invitations::class)->pending($org->id))->toHaveCount(0);
    Mail::assertNothingSent();

    // …and the JS-invoked roster select refuses without demoting anyone.
    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Admin);

    foreach (['archduke', 'viewer'] as $crafted) {
        test()->from(route('environment.organizations.show', $org->id))
            ->patch(route('environment.organizations.members.role', [$org->id, $user->id]), ['role' => $crafted])
            ->assertSessionHasErrors(['role' => 'Choose one of: Member, Admin, Owner.']);

        expect(app(Memberships::class)->of($org->id, $user->id)?->role)->toBe(MembershipRole::Admin);
    }
});

it('refuses a crafted role on the env-admin user detail page', function (): void {
    craftedEnvAdmin();

    $org = app(Organizations::class)->create(new NewOrganization(name: 'Tenant', slug: 'tenant-user-crafted'));
    $user = app(Subjects::class)->create('erin@acme.example', 'Erin');

    foreach (['archduke', 'viewer'] as $crafted) {
        assignUserToOrganization($user->id, $org->id, ['role' => $crafted])
            ->assertSessionHasErrors(['role' => 'Choose one of: Member, Admin, Owner.']);
    }

    expect(app(Memberships::class)->of($org->id, $user->id))->toBeNull();

    app(Memberships::class)->add($org->id, $user->id, MembershipRole::Member);

    foreach (['archduke', 'viewer'] as $crafted) {
        test()->from(route('environment.users.show', $user->id))
            ->patch(route('environment.users.organizations.role', [$user->id, $org->id]), ['role' => $crafted])
            ->assertSessionHasErrors(['role' => 'Choose one of: Member, Admin, Owner.']);

        expect(app(Memberships::class)->of($org->id, $user->id)?->role)->toBe(MembershipRole::Member);
    }
});
