<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use App\Platform\RevokingAuthPolicies;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * {@see RevokingAuthPolicies} — a tightened SSO mandate ends the sessions that predate it.
 *
 * The mandate is the one rule in an {@see AuthPolicy} that no live session is ever
 * re-asked: two-factor and password age are holds in the authentication middleware, so
 * switching them on governs everybody already signed in, but "a password is not a way in"
 * was asked at the door and nowhere else. Every password session simply stayed open.
 *
 * Two things are under test and the second is the one that rots. The revocation itself —
 * delete it and these go red — and its CONDITION: a test that only tightens passes just as
 * happily against code that revokes on every write, which would log a tenant out for
 * relaxing a rule, or for saving an unrelated field on the same form.
 */

/** A live password session for a fresh subject in the ambient environment. */
function aPasswordSession(string $email): Session
{
    $subject = app(Subjects::class)->create($email, 'Person', 'a-strong-unbreached-passphrase');

    return app(SessionManager::class)->start($subject->id, null, ['pwd']);
}

function isLive(Session $session): bool
{
    return app(SessionManager::class)->active($session->id) !== null;
}

it('ends every live session in the environment when the baseline starts mandating SSO', function (): void {
    $session = aPasswordSession('baseline@acme.test');

    expect(isLive($session))->toBeTrue();

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required));

    expect(isLive($session))->toBeFalse();
})->group('security');

/**
 * The LOOSENING half, without which the test above is satisfied by an unconditional
 * revocation. Relaxing a rule strands people who were obeying it, and an operator who
 * turns a mandate back off has said the opposite of "everybody out".
 */
it('leaves live sessions alone when the mandate is relaxed', function (): void {
    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required));

    // Signed in UNDER the mandate — through the provider it mandates, as far as this
    // class is concerned — so nothing about this session predates anything.
    $session = aPasswordSession('relaxed@acme.test');

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Off));

    expect(isLive($session))->toBeTrue();
})->group('security');

/**
 * And the same for a save that never touches SSO. The console writes the WHOLE policy on
 * every submit, so an unconditional revocation would sign the environment out for a
 * one-character change to the minimum password length.
 */
it('leaves live sessions alone when a policy is saved without tightening SSO', function (): void {
    $session = aPasswordSession('unrelated@acme.test');

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(
        minLength: 20,
        mfa: MfaRequirement::Required,
        sso: SsoEnforcement::Preferred,
    ));

    expect(isLive($session))->toBeTrue();
})->group('security');

/**
 * No exemption for whoever made the change.
 *
 * They have just declared that a password is no longer a way in. Exempting the one person
 * best placed to notice would make the declaration false exactly where it is most visible,
 * and the way back in is the same click it now is for everybody else.
 */
it('does not exempt the session that made the change', function (): void {
    platformRootDeployment();

    $actor = app(Subjects::class)->create('actor@acme.test', 'Actor', 'a-strong-unbreached-passphrase');
    signInAsSubject($actor->id);

    $sessionId = session(PlatformAuth::SESSION_KEY);
    expect($sessionId)->toBeString();

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required));

    expect(app(SessionManager::class)->active((string) $sessionId))->toBeNull();
})->group('security');

/**
 * An organization's override governs its MEMBERS — the population
 * {@see PlatformAuth::localSignInAllowedFor()} walks — and nobody else in the
 * environment. A revocation wider than the policy is an outage.
 */
it('ends the sessions of an organization\'s members, and only theirs', function (): void {
    $member = aPasswordSession('member@acme.test');
    $bystander = aPasswordSession('bystander@acme.test');

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.uniqid()));
    app(Memberships::class)->add($org->id, $member->user_id, MembershipRole::Member);

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    expect(isLive($member))->toBeFalse()
        ->and(isLive($bystander))->toBeTrue();
})->group('security');

/**
 * The tightening is measured on the EFFECTIVE policy, not the stored override. An
 * organization with no override inherits the baseline, so "no override" is not "passwords
 * allowed" — and an override that merely restates a mandate the environment already
 * imposes changes nothing for anyone.
 */
it('revokes nobody when an override restates a mandate the environment already imposes', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.uniqid()));

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required));

    // After the baseline, so this session is not the one the baseline write ended.
    $member = aPasswordSession('restated@acme.test');
    app(Memberships::class)->add($org->id, $member->user_id, MembershipRole::Member);

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    expect(isLive($member))->toBeTrue();
})->group('security');

/**
 * Through the surface an operator actually uses, because a decorator that the container
 * never wraps is a class nobody calls.
 *
 * It also fixes the population question in place. The environment admin writing this page
 * is an ACCOUNT member — a subject of the platform root — and the baseline they save here
 * governs the environment's own subjects, not their own password. Their session surviving
 * is the environment scope holding, not an exemption: the same person tightening the ROOT
 * baseline goes with everybody else (see {@see MemberCredentialGateTest}).
 */
it('ends the environment\'s sessions when the sign-in rules page mandates SSO', function (): void {
    platformRootEnvironment();
    $r = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'rules-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($r->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
    actAsEnvironmentAdmin($r->member, $r->environment->id);

    // A tenant subject of the environment being administered.
    $tenantSession = aPasswordSession('tenant-user@acme.test');
    $adminSessionId = session(PlatformAuth::SESSION_KEY);

    // TWO calls, and the first one deliberately writes nothing. The page holds a mandate
    // that would end sessions on a confirmation step, because the alternative is a select
    // and a Save button that sign an environment's users out with no warning that they
    // were about to. `save()` alone must leave every session standing.
    $page = Volt::test('console.auth-policy')
        ->set('sso', 'required')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('confirmingTightening', true)
        ->assertSee('This will sign people out of');

    expect(isLive($tenantSession))->toBeTrue();

    $page->call('confirmSave')->assertHasNoErrors()->assertSet('confirmingTightening', false);

    expect(isLive($tenantSession))->toBeFalse();

    // The administrator's own session lives in the platform root, which this policy does
    // not govern — asked in that scope, because a root session is invisible from here.
    expect(app(PlatformRoot::class)->run(
        fn (): bool => app(SessionManager::class)->active((string) $adminSessionId) !== null,
    ))->toBeTrue();
})->group('security');
