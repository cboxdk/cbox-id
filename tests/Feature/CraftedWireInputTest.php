<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

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
    app(Memberships::class)->add($org->id, $subject->id, 'admin');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, app(Organizations::class)->find($org->id), MembershipRole::Admin);

    Volt::test('hooks')
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

    session()->put(AccountAuth::SESSION_KEY, $mine->member->id);

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
