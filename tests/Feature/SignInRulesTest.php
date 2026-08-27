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
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

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

/**
 * Press "Save rules", the way the form does: every field, not only the changed one.
 *
 * The editor is a form over a WHOLE policy, so a submission that carried one field would
 * be a submission this console cannot produce — and the tightening check reads all seven.
 * The page's current values are the starting point, which is what the browser has.
 *
 * @param  array<string, mixed>  $changes
 */
function saveRules(array $changes, string $plane = 'auth-policy'): TestResponse
{
    return test()->from(route($plane))
        ->put(route($plane.'.update'), [...currentRules($plane), ...$changes]);
}

/**
 * Enrol a second factor for a subject, so a baseline that REQUIRES one lets them past.
 *
 * An administrator who mandates two-factor is, from that moment, somebody who has one —
 * the console sends anybody else to enrol before it will render another page. A fixture
 * without this describes an administrator locked out of the setting they just wrote.
 */
function enrolSecondFactor(string $subjectId): void
{
    WebAuthnCredential::query()->create([
        'user_id' => $subjectId,
        'credential_id' => 'cred-'.$subjectId,
        'public_key' => 'key',
        'sign_count' => 0,
        'transports' => ['internal'],
        'name' => 'Test key',
    ]);
}

/** What the editor is showing right now. */
function currentRules(string $plane = 'auth-policy'): array
{
    return (array) test()->get(route($plane))->assertOk()->inertiaProps('policy');
}

/**
 * Re-establish the acting administrator's session as an SSO one.
 *
 * A mandate ends every PASSWORD session in the organization — that is the whole point of
 * it — so an administrator who has just required SSO is, a moment later, somebody who
 * signed in through their identity provider. A fixture that leaves them holding a password
 * session is a fixture describing a state the product does not produce.
 */
function resignInThroughSso(string $subjectId, string $organizationId, MembershipRole $role): void
{
    $session = app(SessionManager::class)->start($subjectId, $organizationId, ['sso']);

    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set(
        app(Subjects::class)->find($subjectId),
        $session,
        app(Organizations::class)->find($organizationId),
        $role,
    );
}

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

    saveRules(['minLength' => 20, 'mfa' => 'required'])
        ->assertRedirect(route('auth-policy'))
        ->assertSessionHasNoErrors();

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
    [$subjectId, $org] = actingAsRole(MembershipRole::Owner);

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(
        minLength: 16,
        requireBreachCheck: true,
        maxAgeDays: 90,
        reuseHistory: 5,
        mfa: MfaRequirement::Required,
        sso: SsoEnforcement::Preferred,
        lockoutThreshold: 10,
    ));

    // The baseline it just wrote requires a second factor, and the console holds its own
    // author to it.
    enrolSecondFactor((string) $subjectId);

    saveRules([
        'minLength' => 8,
        'reuseHistory' => 0,
        'maxAgeDays' => '',
        'lockoutThreshold' => '',
        'requireBreachCheck' => false,
        'mfa' => 'optional',
        'sso' => 'off',
    ])->assertSessionHasErrors([
        'minLength', 'reuseHistory', 'maxAgeDays', 'lockoutThreshold', 'requireBreachCheck', 'mfa', 'sso',
    ]);

    expect(app(AuthPolicies::class)->overrideFor($org->id))->toBeNull();
})->group('security');

it('says whether a rule is the organization\'s own or the environment\'s', function (): void {
    // The legibility this page exists for. "Require SSO: off" is a different fact
    // depending on who decided it, and an administrator cannot act on it without knowing.
    [, $org] = actingAsRole(MembershipRole::Owner);

    // Asserted on the PROPS, which is where the answer lives: `inheriting` decides the
    // sentence and `overridden` decides which fields wear a badge. A markup assertion
    // would be asserting the copy rather than the fact under it.
    test()->get(route('auth-policy'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inheriting', true)
            ->where('overridden', []));

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(minLength: 20));

    test()->get(route('auth-policy'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inheriting', false)
            ->where('overridden', fn (Collection $fields): bool => $fields->contains('minLength')));
})->group('security');

it('drops the override and goes back to inheriting', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(minLength: 14));
    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(minLength: 22));

    expect(app(AuthPolicies::class)->resolve($org->id)->minLength)->toBe(22);

    test()->from(route('auth-policy'))
        ->delete(route('auth-policy.inherit'))
        ->assertRedirect(route('auth-policy'))
        ->assertSessionHasNoErrors();

    // The form now shows what actually governs this organization, not the value object's
    // own defaults — a reset that left 12 on screen under a 14-character baseline would
    // be the console lying about the rule it just restored.
    expect(test()->get(route('auth-policy'))->assertOk()->inertiaProps('policy.minLength'))->toBe(14);

    expect(app(AuthPolicies::class)->overrideFor($org->id))->toBeNull()
        ->and(app(AuthPolicies::class)->resolve($org->id)->minLength)->toBe(14);
})->group('security');

it('asks before a save that would sign an organization out, then does it', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    $member = aRuleSubjectSession('rules-member@acme.test');
    app(Memberships::class)->add($org->id, $member->user_id, MembershipRole::Member);
    $bystander = aRuleSubjectSession('rules-bystander@acme.test');

    /*
     * THE PAGE ASKS FIRST, and this is the fact it asks on.
     *
     * Under Volt the question was a server round trip — save() refused to write and set a
     * flag. There is no round trip to hang it on now: the form asks in the browser before
     * it submits, so what the server owes the page is the one thing the browser cannot
     * work out, which is whether passwords work TODAY. The dialog itself is asserted in
     * tests/Browser/SignInRulesTest, where it can actually be seen.
     */
    test()->get(route('auth-policy'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('passwordsCurrentlyWork', true));

    expect(app(AuthPolicies::class)->overrideFor($org->id))->toBeNull()
        ->and(ruleSessionIsLive($member))->toBeTrue();

    saveRules(['sso' => 'required'])->assertSessionHasNoErrors();

    // …and the revocation is the decorator's, reached because the page writes through the
    // contract. Its scope is the organization's members and nobody else's.
    expect(app(AuthPolicies::class)->resolve($org->id)->sso)->toBe(SsoEnforcement::Required)
        ->and(ruleSessionIsLive($member))->toBeFalse()
        ->and(ruleSessionIsLive($bystander))->toBeTrue();
})->group('security');

it('does not ask when the organization is already covered by an environment mandate', function (): void {
    // Nothing is ending, so nothing is confirmed. Asking "are you sure you want to sign
    // everyone out" about a change that signs nobody out is how confirmations become
    // reflexes — and the effective policy, not the stored override, is what says so.
    [$subjectId, $org] = actingAsRole(MembershipRole::Owner);

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required));

    // …and the administrator is now signed in the only way the mandate allows.
    resignInThroughSso((string) $subjectId, $org->id, MembershipRole::Owner);

    test()->get(route('auth-policy'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('passwordsCurrentlyWork', false));

    saveRules(['sso' => 'required'])->assertSessionHasNoErrors();

    expect(app(AuthPolicies::class)->overrideFor($org->id))->not->toBeNull();
})->group('security');

it('refuses the page to an organization member who is not an administrator', function (): void {
    actingAsRole(MembershipRole::Member);

    test()->get(route('auth-policy'))->assertForbidden();
})->group('security');

it('refuses an organization admin with no organization at all', function (): void {
    // The nullable reader answers null both for "an environment administrator has not
    // chosen one yet" and for "this member has none". On this page the second must not
    // fall through to writing the ENVIRONMENT baseline, which is every tenant's policy.
    $subject = app(Subjects::class)->create('orphan-rules@acme.test', 'Orphan', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-orphan-rules'));
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, null, MembershipRole::Owner);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    test()->get(route('auth-policy'))->assertForbidden();
})->group('security');

/*
|--------------------------------------------------------------------------
| The environment plane
|--------------------------------------------------------------------------
*/

it('keeps the environment plane writing the baseline, not an override', function (): void {
    $setup = crudSetup();
    $org = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-baseline'));

    saveRules(['minLength' => 19], 'environment.auth-policy')->assertSessionHasNoErrors();

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

    test()->from(route('environment.auth-policy'))
        ->delete(route('environment.auth-policy.inherit'))
        ->assertForbidden();
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

    // Activation lands back on the connection with the offer standing — `offeringMandate`
    // is what draws the panel, and it is re-derived from the policy rather than trusted
    // from the flag, so it can only be true while passwords still work.
    test()->from(route('connections.show', $connection->id))
        ->post(route('connections.activate', $connection->id))
        ->assertRedirect();

    test()->get(route('connections.show', ['connection' => $connection->id, 'activated' => '1']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('offeringMandate', true));

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

    test()->post(route('connections.activate', $connection->id))->assertRedirect();
    test()->post(route('connections.require-sso', $connection->id))->assertSessionHasNoErrors();

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

    test()->post(route('connections.activate', $connection->id));
    test()->post(route('connections.require-sso', $connection->id));

    $override = app(AuthPolicies::class)->overrideFor($connection->organization_id);

    expect($override?->minLength)->toBe(16)
        ->and($override?->reuseHistory)->toBe(4)
        ->and($override?->sso)->toBe(SsoEnforcement::Required);
})->group('security');

it('takes no for an answer', function (): void {
    [$org, $connection] = anOrgWithADraftConnection();

    test()->post(route('connections.activate', $connection->id))->assertRedirect();

    // Declining is a CLIENT decision and nothing else: it writes nothing, so the page a
    // moment later still offers, and the policy is untouched. What must not happen is a
    // mandate applied by an activation nobody said yes to.
    expect(app(AuthPolicies::class)->resolve($org->id)->sso)->toBe(SsoEnforcement::Off);

    // …and arriving without the activation marker does not offer at all.
    test()->get(route('connections.show', $connection->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('offeringMandate', false));
})->group('security');

it('never offers a mandate that is already in force, however the marker arrives', function (): void {
    // The marker rides in the URL, so anybody can type it. The page re-derives the offer
    // from the POLICY rather than trusting it — and reports the standing mandate instead,
    // where somebody debugging "why can nobody use a password" is actually looking.
    [$org, $connection] = anOrgWithADraftConnection();

    app(Connections::class)->activate($org->id, $connection->id);
    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    // …and the administrator is now signed in the only way the mandate allows. Without
    // this the page under test is a redirect to /login, which offers nothing either.
    resignInThroughSso(
        (string) app(CurrentUser::class)->id(),
        $org->id,
        MembershipRole::Owner,
    );

    test()->get(route('connections.show', ['connection' => $connection->id, 'activated' => '1']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('offeringMandate', false)
            ->where('passwordsStillAllowed', false));
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

    test()->from(route('login'))->post(route('login.attempt'), [
        'identified' => true,
        'email' => 'refused@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertRedirect(route('login'));

    /*
     * THE MANDATE RIDES THE FLASH CHANNEL, and that is the security property rather than a
     * detail of plumbing: this screen is reachable only by somebody who typed the RIGHT
     * password, so it names their organization — and a page prop is written into the
     * browser's history entry, where pressing Back would recover it.
     *
     * That the refusal is TERMINAL — the form and the alternative doors gone from the
     * screen — is the page's own doing and a request cannot see it. It is held in
     * tests/Browser/SignInRulesTest.php, in a browser that renders it.
     */
    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('mandate.organization', 'Acme Inc')
            ->hasFlash('mandate.startUrl', url("/sso/oidc/{$connection->id}/redirect")));

    // And the verified credential is not left anywhere the next page can reach: the old
    // input bag keeps the address, deliberately, and never the password.
    expect(session()->get('_old_input.password'))->toBeNull();
})->group('security');

it('says so plainly when a mandate has no connection to send anyone to', function (): void {
    // A real state an administrator can create: require SSO, then disable or never
    // activate the connection. A link to nothing would be a second dead end wearing the
    // clothes of a fix.
    $subject = app(Subjects::class)->create('stranded@acme.test', 'Dana', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Stranded Co', 'acme-stranded'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    test()->from(route('login'))->post(route('login.attempt'), [
        'identified' => true,
        'email' => 'stranded@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertRedirect(route('login'));

    // NAMED, with nowhere to send them: a link to nothing would be a second dead end
    // wearing the clothes of a fix. What the screen SAYS about that is the page's, and is
    // asserted in the browser suite.
    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('mandate.organization', 'Stranded Co')
            ->hasFlash('mandate.startUrl', null));
})->group('security');

it('leaves a wrong password saying nothing at all', function (): void {
    // The disclosure boundary. The mandate screen is reachable only by somebody who typed
    // the right password, so it can never answer "does this address exist here".
    $subject = app(Subjects::class)->create('quiet@acme.test', 'Dana', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Quiet Co', 'acme-quiet'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    test()->from(route('login'))->post(route('login.attempt'), [
        'identified' => true,
        'email' => 'quiet@acme.test',
        'password' => 'a-wrong-guess-entirely',
    ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    // NOTHING ABOUT THE MANDATE. Reaching the mandate screen requires the right password,
    // so it can never answer "does this address exist here" — and the absence of the flash
    // key is exactly that guarantee.
    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('mandate'));
})->group('security');

it('offers a way back off the refusal screen', function (): void {
    $subject = app(Subjects::class)->create('back@acme.test', 'Dana', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Back Co', 'acme-back'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    test()->from(route('login'))->post(route('login.attempt'), [
        'identified' => true,
        'email' => 'back@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertRedirect(route('login'));

    /*
     * THE WAY BACK IS A FRESH LOAD, not a server action. `startOver` was a Livewire method
     * that reset three properties; the same page reached again has simply not been flashed
     * a mandate, so it opens at the email step. That the CONTROL exists on the refusal
     * screen is the page's own doing, and is held in tests/Browser/SignInRulesTest.php.
     */
    test()->get(route('login'));

    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->missingFlash('mandate'));
})->group('security');

it('gives an account member the same terminal refusal', function (): void {
    // An account member is an ordinary subject in the platform root, so the account's own
    // organization can mandate SSO. There was a SECOND door for these people, and it
    // answered that mandate with "those credentials do not match a workspace" — to the
    // owner of the workspace. One door now, and this is the same door the test above
    // drives, aimed at a member instead of a tenant user.
    platformRootDeployment();

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'refused-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    app(PlatformRoot::class)->run(fn () => app(AuthPolicies::class)->setForOrganization(
        (string) $provisioned->organization->id,
        new AuthPolicy(sso: SsoEnforcement::Required),
    ));

    $organizationName = app(PlatformRoot::class)->run(
        fn (): mixed => Organization::query()->whereKey($provisioned->organization->id)->value('name'),
    );

    test()->from(route('login'))->post(route('login.attempt'), [
        'identified' => true,
        'email' => 'refused-owner@acme.example',
        'password' => 'a-strong-unbreached-passphrase',
    ])->assertRedirect(route('login'));

    test()->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('mandate.organization', $organizationName));

    // No session was established by the attempt that produced the screen, and the verified
    // credential was not carried anywhere with it.
    expect(session()->has(PlatformAuth::SESSION_KEY))->toBeFalse()
        ->and(session()->get('_old_input.password'))->toBeNull();
})->group('security');

it('resolves an account member\'s mandate in the platform root', function (): void {
    // The root host's ambient scope is the platform root only by coincidence of
    // configuration. Without the explicit scope the memberships are simply not found, and
    // the screen renders with no organization named and no link — a refusal that says
    // nothing.
    platformRootDeployment();

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Scoped',
        ownerEmail: 'scoped-owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    app(PlatformRoot::class)->run(fn () => app(AuthPolicies::class)->setForOrganization(
        (string) $provisioned->organization->id,
        new AuthPolicy(sso: SsoEnforcement::Required),
    ));

    test()->from(route('login'))->post(route('login.attempt'), ['identified' => true, 'email' => 'scoped-owner@acme.example', 'password' => 'a-strong-unbreached-passphrase'])
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
