<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Volt\Volt;

/**
 * Sign-in rules — the capability that was enforced everywhere and authorable nowhere.
 *
 * {@see AuthPolicies::setForOrganization()} and {@see AuthPolicies::clearForOrganization()}
 * had no caller in the product: no console page, no API route, no command. Meanwhile both
 * password doors consulted what they write on every attempt. These tests are about the
 * three things that closes — a surface on both planes, an offer at the moment somebody has
 * just decided how their company signs in, and a refusal that goes somewhere.
 */
beforeEach(fn () => app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck));

/** A live password session for a subject, the way a real sign-in leaves one. */
function aRuleSubjectSession(string $email): Session
{
    $subject = app(Subjects::class)->create($email, 'Person', 'a-strong-unbreached-passphrase');

    return app(SessionManager::class)->start($subject->id, null, ['pwd']);
}

function ruleSessionIsLive(Session $session): bool
{
    return app(SessionManager::class)->active($session->id) !== null;
}

/*
|--------------------------------------------------------------------------
| The organization plane — the half that never existed
|--------------------------------------------------------------------------
*/

it('writes an organization override from the organization console', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    // Nothing of its own to begin with: the org is governed entirely by the baseline.
    expect(app(AuthPolicies::class)->overrideFor($org->id))->toBeNull();

    Volt::test('console.auth-policy')
        ->set('minLength', 20)
        ->set('mfa', 'required')
        ->call('save')
        ->assertHasNoErrors();

    $override = app(AuthPolicies::class)->overrideFor($org->id);

    expect($override)->not->toBeNull()
        ->and($override?->minLength)->toBe(20)
        ->and($override?->mfa)->toBe(MfaRequirement::Required)
        // …and it is in force, which is the whole reason the surface had to exist.
        ->and(app(AuthPolicies::class)->resolve($org->id)->minLength)->toBe(20);
})->group('security');

it('refuses an override that would loosen the environment baseline', function (): void {
    // `tightenedWith()` silently discards a looser value, so storing one leaves the
    // console showing a rule that is in force nowhere. Refused with the floor named
    // instead — on every field, because a page that catches only the obvious one teaches
    // an administrator to trust the rest.
    [, $org] = actingAsRole(MembershipRole::Owner);

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(
        minLength: 16,
        requireBreachCheck: true,
        maxAgeDays: 90,
        reuseHistory: 5,
        mfa: MfaRequirement::Required,
        sso: SsoEnforcement::Preferred,
        lockoutThreshold: 10,
    ));

    Volt::test('console.auth-policy')
        ->set('minLength', 8)
        ->set('reuseHistory', 0)
        ->set('maxAgeDays', '')
        ->set('lockoutThreshold', '')
        ->set('requireBreachCheck', false)
        ->set('mfa', 'optional')
        ->set('sso', 'off')
        ->call('save')
        ->assertHasErrors(['minLength', 'reuseHistory', 'maxAgeDays', 'lockoutThreshold', 'requireBreachCheck', 'mfa', 'sso']);

    expect(app(AuthPolicies::class)->overrideFor($org->id))->toBeNull();
})->group('security');

it('says whether a rule is the organization\'s own or the environment\'s', function (): void {
    // The legibility this page exists for. "Require SSO: off" is a different fact
    // depending on who decided it, and an administrator cannot act on it without knowing.
    [, $org] = actingAsRole(MembershipRole::Owner);

    // Matched without the apostrophe: it is literal markup rather than escaped output, so
    // assertSee's own escaping would look for an entity the page never renders.
    Volt::test('console.auth-policy')->assertSee('Using your environment');

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(minLength: 20));

    Volt::test('console.auth-policy')
        ->assertSee('has its own rules')
        ->assertSee('Overridden');
})->group('security');

it('drops the override and goes back to inheriting', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(minLength: 14));
    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(minLength: 22));

    expect(app(AuthPolicies::class)->resolve($org->id)->minLength)->toBe(22);

    Volt::test('console.auth-policy')
        ->call('returnToInherited')
        ->assertHasNoErrors()
        // The form now shows what actually governs this organization, not the value
        // object's own defaults — a reset that left 12 on screen under a 14-character
        // baseline would be the console lying about the rule it just restored.
        ->assertSet('minLength', 14);

    expect(app(AuthPolicies::class)->overrideFor($org->id))->toBeNull()
        ->and(app(AuthPolicies::class)->resolve($org->id)->minLength)->toBe(14);
})->group('security');

it('asks before a save that would sign an organization out, then does it', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    $member = aRuleSubjectSession('rules-member@acme.test');
    app(Memberships::class)->add($org->id, $member->user_id, MembershipRole::Member);
    $bystander = aRuleSubjectSession('rules-bystander@acme.test');

    $page = Volt::test('console.auth-policy')
        ->set('sso', 'required')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('confirmingTightening', true)
        ->assertSee('This will sign people out of');

    // The first click writes NOTHING. A mandate that took effect on the way to asking
    // would make the question decoration.
    expect(app(AuthPolicies::class)->overrideFor($org->id))->toBeNull()
        ->and(ruleSessionIsLive($member))->toBeTrue();

    $page->call('confirmSave')->assertHasNoErrors();

    // …and the revocation is the decorator's, reached because the page writes through the
    // contract. Its scope is the organization's members and nobody else's.
    expect(app(AuthPolicies::class)->resolve($org->id)->sso)->toBe(SsoEnforcement::Required)
        ->and(ruleSessionIsLive($member))->toBeFalse()
        ->and(ruleSessionIsLive($bystander))->toBeTrue();
})->group('security');

it('lets the confirmation be declined without writing anything', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    Volt::test('console.auth-policy')
        ->set('sso', 'required')
        ->call('save')
        ->call('cancelTightening')
        ->assertSet('confirmingTightening', false)
        ->assertSee('Save rules');

    expect(app(AuthPolicies::class)->overrideFor($org->id))->toBeNull();
})->group('security');

it('does not ask when the organization is already covered by an environment mandate', function (): void {
    // Nothing is ending, so nothing is confirmed. Asking "are you sure you want to sign
    // everyone out" about a change that signs nobody out is how confirmations become
    // reflexes — and the effective policy, not the stored override, is what says so.
    [, $org] = actingAsRole(MembershipRole::Owner);

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required));

    Volt::test('console.auth-policy')
        ->set('sso', 'required')
        ->call('save')
        ->assertSet('confirmingTightening', false);

    expect(app(AuthPolicies::class)->overrideFor($org->id))->not->toBeNull();
})->group('security');

it('refuses the page to an organization member who is not an administrator', function (): void {
    actingAsRole(MembershipRole::Member);

    Volt::test('console.auth-policy')->assertForbidden();
})->group('security');

it('refuses an organization admin with no organization at all', function (): void {
    // The nullable reader answers null both for "an environment administrator has not
    // chosen one yet" and for "this member has none". On this page the second must not
    // fall through to writing the ENVIRONMENT baseline, which is every tenant's policy.
    $subject = app(Subjects::class)->create('orphan-rules@acme.test', 'Orphan', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-orphan-rules'));
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Owner);

    Volt::test('console.auth-policy')->assertForbidden();
})->group('security');

/*
|--------------------------------------------------------------------------
| The environment plane
|--------------------------------------------------------------------------
*/

it('keeps the environment plane writing the baseline, not an override', function (): void {
    $setup = crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-baseline'));

    Volt::test('console.auth-policy')
        ->set('minLength', 19)
        ->call('save')
        ->assertHasNoErrors();

    expect(app(AuthPolicies::class)->forEnvironment()->minLength)->toBe(19)
        // No override was invented for anybody: the organization simply inherits.
        ->and(app(AuthPolicies::class)->overrideFor($org->id))->toBeNull()
        ->and(app(AuthPolicies::class)->resolve($org->id)->minLength)->toBe(19)
        ->and($setup['envId'])->not->toBe('');
})->group('security');

it('refuses the environment baseline an attempt to inherit from itself', function (): void {
    // Forged, not clicked: the control is not rendered on this plane, so the refusal has
    // to live in the action. There is nothing above the baseline to fall back to, and a
    // clearForOrganization() driven from here would need an organization id it does not
    // have — which is exactly the write that would land somewhere unintended.
    crudSetup();

    Volt::test('console.auth-policy')
        ->call('returnToInherited')
        ->assertHasErrors('sso');
})->group('security');

/*
|--------------------------------------------------------------------------
| The offer, at the moment SSO is connected
|--------------------------------------------------------------------------
*/

/** An organization with a SAML connection, and its admin signed in on the org plane. */
function anOrgWithADraftConnection(): array
{
    [, $org] = actingAsRole(MembershipRole::Owner);

    $connection = app(Connections::class)->create(
        $org->id,
        ConnectionType::Saml,
        'Acme IdP',
        [
            'idp_entity_id' => 'https://idp.acme.test/metadata',
            'idp_sso_url' => 'https://idp.acme.test/sso',
            'idp_x509cert' => 'MIIBIjAN',
            'sp_entity_id' => 'https://cbox.test/sp',
            'sp_acs_url' => 'https://cbox.test/acs',
        ],
    );

    return [$org, $connection];
}

it('offers the mandate when a connection is activated, and does not apply it', function (): void {
    [$org, $connection] = anOrgWithADraftConnection();

    $member = aRuleSubjectSession('offer-member@acme.test');
    app(Memberships::class)->add($org->id, $member->user_id, MembershipRole::Member);

    Volt::test('console.connections.show', ['connection' => $connection->id])
        ->call('activate')
        ->assertSet('offerMandate', true)
        ->assertSee('Make Acme IdP the only way in?')
        ->assertSee('including yours');

    // OFFERED. Turning it on ends every password session in the organization, so an
    // activation that flipped it silently would sign the administrator out of the page
    // they were standing on.
    expect(app(AuthPolicies::class)->resolve($org->id)->sso)->toBe(SsoEnforcement::Off)
        ->and(ruleSessionIsLive($member))->toBeTrue();
})->group('security');

it('applies the mandate when the offer is accepted', function (): void {
    [$org, $connection] = anOrgWithADraftConnection();

    $member = aRuleSubjectSession('accept-member@acme.test');
    app(Memberships::class)->add($org->id, $member->user_id, MembershipRole::Member);

    Volt::test('console.connections.show', ['connection' => $connection->id])
        ->call('activate')
        ->call('requireSso')
        ->assertSet('offerMandate', false)
        ->assertHasNoErrors();

    expect(app(AuthPolicies::class)->resolve($org->id)->sso)->toBe(SsoEnforcement::Required)
        // Written through the contract, so the decorator ended the sessions the mandate
        // has just made illegitimate.
        ->and(ruleSessionIsLive($member))->toBeFalse();
})->group('security');

it('keeps the rest of the organization\'s policy when the offer is accepted', function (): void {
    // The override is the EXISTING policy with the mandate raised. Built from a bare
    // AuthPolicy it would write the value object's defaults as this organization's first
    // override — quietly restating a 12-character minimum under a 16-character baseline.
    [, $connection] = anOrgWithADraftConnection();

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(minLength: 16, reuseHistory: 4));

    Volt::test('console.connections.show', ['connection' => $connection->id])
        ->call('activate')
        ->call('requireSso');

    $override = app(AuthPolicies::class)->overrideFor($connection->organization_id);

    expect($override?->minLength)->toBe(16)
        ->and($override?->reuseHistory)->toBe(4)
        ->and($override?->sso)->toBe(SsoEnforcement::Required);
})->group('security');

it('takes no for an answer', function (): void {
    [$org, $connection] = anOrgWithADraftConnection();

    Volt::test('console.connections.show', ['connection' => $connection->id])
        ->call('activate')
        ->call('keepPasswords')
        ->assertSet('offerMandate', false)
        ->assertDontSee('Make Acme IdP the only way in?');

    expect(app(AuthPolicies::class)->resolve($org->id)->sso)->toBe(SsoEnforcement::Off);
})->group('security');

it('never offers a mandate that is already in force, however the flag arrives', function (): void {
    // `offerMandate` is a public property and therefore client-settable, so the page
    // re-derives the offer from the policy rather than trusting the flag — and shows the
    // standing mandate instead, where somebody debugging "why can nobody use a password"
    // is actually looking.
    [$org, $connection] = anOrgWithADraftConnection();

    app(Connections::class)->activate($org->id, $connection->id);
    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    Volt::test('console.connections.show', ['connection' => $connection->id])
        ->set('offerMandate', true)
        ->assertDontSee('Make Acme IdP the only way in?')
        ->assertSee('Single sign-on is required');
})->group('security');

/*
|--------------------------------------------------------------------------
| The refusal, made reachable
|--------------------------------------------------------------------------
*/

it('tells a tenant user to use their identity provider instead of that their password is wrong', function (): void {
    $subject = app(Subjects::class)->create('refused@acme.test', 'Dana', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme Inc', 'acme-refusal'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    $connection = app(Connections::class)->create(
        $org->id,
        ConnectionType::Oidc,
        'Acme IdP',
        ['issuer' => 'https://idp.acme.test', 'client_id' => 'abc', 'client_secret' => 's', 'signing_key' => 'k'],
    );
    app(Connections::class)->activate($org->id, $connection->id);

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    Volt::test('auth.login')
        ->set('identified', true)
        ->set('email', 'refused@acme.test')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('login')
        ->assertSet('ssoOrganization', 'Acme Inc')
        ->assertSet('ssoStartUrl', url("/sso/oidc/{$connection->id}/redirect"))
        ->assertSee('Acme Inc requires single sign-on')
        // Terminal: the form it just refused is gone, along with the alternative doors
        // this page offers. Leaving them on screen invites the same attempt again.
        ->assertDontSee('Forgot password?')
        ->assertDontSee('Email me a magic link')
        // …and the verified credential is not left in the snapshot of the page that
        // replaces the form.
        ->assertSet('password', '');
})->group('security');

it('says so plainly when a mandate has no connection to send anyone to', function (): void {
    // A real state an administrator can create: require SSO, then disable or never
    // activate the connection. A link to nothing would be a second dead end wearing the
    // clothes of a fix.
    $subject = app(Subjects::class)->create('stranded@acme.test', 'Dana', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Stranded Co', 'acme-stranded'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    Volt::test('auth.login')
        ->set('identified', true)
        ->set('email', 'stranded@acme.test')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('login')
        ->assertSet('ssoStartUrl', null)
        ->assertSee('No identity provider is connected');
})->group('security');

it('leaves a wrong password saying nothing at all', function (): void {
    // The disclosure boundary. The mandate screen is reachable only by somebody who typed
    // the right password, so it can never answer "does this address exist here".
    $subject = app(Subjects::class)->create('quiet@acme.test', 'Dana', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Quiet Co', 'acme-quiet'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    Volt::test('auth.login')
        ->set('identified', true)
        ->set('email', 'quiet@acme.test')
        ->set('password', 'a-wrong-guess-entirely')
        ->call('login')
        ->assertSet('ssoOrganization', null)
        ->assertHasErrors('email')
        ->assertDontSee('requires single sign-on');
})->group('security');

it('offers a way back off the refusal screen', function (): void {
    $subject = app(Subjects::class)->create('back@acme.test', 'Dana', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Back Co', 'acme-back'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    Volt::test('auth.login')
        ->set('identified', true)
        ->set('email', 'back@acme.test')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('login')
        ->call('startOver')
        ->assertSet('ssoOrganization', null)
        // Back to the EMAIL step, not to the password it has just refused.
        ->assertSet('identified', false)
        ->assertSee('Continue');
})->group('security');

it('gives an account member the same terminal refusal', function (): void {
    // An account member is an ordinary subject in the platform root, so the account's own
    // organization can mandate SSO. There was a SECOND door for these people, and it
    // answered that mandate with "those credentials do not match a workspace" — to the
    // owner of the workspace. One door now, and this is the same door the test above
    // drives, aimed at a member instead of a tenant user.
    platformRootDeployment();

    $provisioned = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'refused-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    app(PlatformRoot::class)->run(fn () => app(AuthPolicies::class)->setForOrganization(
        (string) $provisioned->account->organization_id,
        new AuthPolicy(sso: SsoEnforcement::Required),
    ));

    $organizationName = app(PlatformRoot::class)->run(
        fn (): mixed => Organization::query()->whereKey($provisioned->account->organization_id)->value('name'),
    );

    Volt::test('auth.login')
        ->set('identified', true)
        ->set('email', 'refused-owner@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('login')
        ->assertSet('ssoOrganization', $organizationName)
        ->assertSee('requires single sign-on')
        ->assertDontSee('Email me a magic link')
        ->assertSet('password', '');

    // No session was established by the attempt that produced the screen.
    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse();
})->group('security');

it('resolves an account member\'s mandate in the platform root', function (): void {
    // The root host's ambient scope is the platform root only by coincidence of
    // configuration. Without the explicit scope the memberships are simply not found, and
    // the screen renders with no organization named and no link — a refusal that says
    // nothing.
    platformRootDeployment();

    $provisioned = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Scoped',
        ownerEmail: 'scoped-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    app(PlatformRoot::class)->run(fn () => app(AuthPolicies::class)->setForOrganization(
        (string) $provisioned->account->organization_id,
        new AuthPolicy(sso: SsoEnforcement::Required),
    ));

    Volt::test('auth.login')
        ->set('identified', true)
        ->set('email', 'scoped-owner@acme.example')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('login')
        ->assertDontSee('Your organization requires single sign-on');
})->group('security');

it('refuses to write a policy for an organization the scope will not name', function (): void {
    // The write guard, asserted directly rather than through the page: on the environment
    // plane with no organization chosen, an organization-level write has nowhere to land,
    // and a downstream default picking one would legislate for a tenant nobody named.
    crudSetup();
    session()->forget(ConsoleScope::SELECTION_KEY);

    expect(fn (): string => app(ConsoleScope::class)->requireOrganizationId())
        ->toThrow(AuthorizationException::class);
})->group('security');
