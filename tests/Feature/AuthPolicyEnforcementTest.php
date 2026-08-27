<?php

declare(strict_types=1);

use App\Platform\BreachedPasswords;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

// Policy enforcement is the subject here, not the breach lookup — keep it offline.
beforeEach(fn () => app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck));

it('binds the real breach check so requireBreachCheck is not silently inert', function (): void {
    // The framework ships a deliberately-inert default; the app must replace it, or a
    // policy demanding a breach check would pass everything while appearing enforced.
    app()->forgetInstance(BreachedPasswordCheck::class);
    expect(app(BreachedPasswordCheck::class))->toBeInstanceOf(BreachedPasswords::class);
});

it('refuses password sign-in for a subject whose organization mandates SSO', function (): void {
    $subject = app(Subjects::class)->create('sso@acme.test', 'Dana', 'a-strong-unbreached-passphrase');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.uniqid()));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

    $auth = app(PlatformAuth::class);
    $request = Request::create('/login', 'POST');

    // With SSO off, the correct password signs in.
    expect($auth->attemptPassword($request, 'sso@acme.test', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Ok);

    // Once the organization mandates SSO, the SAME correct password is refused — a local
    // credential must not be a way around the identity provider the tenant chose.
    //
    // SsoRequired, not Invalid, and the distinction is the point: the door has to be able
    // to tell the person to use their IdP. Reported as Invalid, this refusal ended in
    // "those credentials do not match our records" — said only ever to people whose
    // credentials matched.
    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(sso: SsoEnforcement::Required));

    expect($auth->attemptPassword($request, 'sso@acme.test', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::SsoRequired);

    // And a WRONG password against the same mandated account stays indistinguishable from
    // every other wrong password. The refusal is reached only after the credential
    // verifies, so it can never answer "this address exists".
    expect($auth->attemptPassword($request, 'sso@acme.test', 'not-the-passphrase'))
        ->toBe(AttemptOutcome::Invalid);
});

it('lets an environment mandate SSO for every organization at once', function (): void {
    $subject = app(Subjects::class)->create('envsso@acme.test', 'Sam', 'a-strong-unbreached-passphrase');

    $auth = app(PlatformAuth::class);
    $request = Request::create('/login', 'POST');

    expect($auth->attemptPassword($request, 'envsso@acme.test', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Ok);

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(sso: SsoEnforcement::Required));

    expect($auth->attemptPassword($request, 'envsso@acme.test', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::SsoRequired);
});

// The strictest membership wins: holding a second, laxer membership must not become a
// way around a mandating tenant.
it('refuses password sign-in when ANY of the subject\'s organizations mandates SSO', function (): void {
    $subject = app(Subjects::class)->create('multi@acme.test', 'Rae', 'a-strong-unbreached-passphrase');
    $orgs = app(Organizations::class);
    $lax = $orgs->create(new NewOrganization('Lax Corp', 'lax-'.uniqid()));
    $strict = $orgs->create(new NewOrganization('Strict Corp', 'strict-'.uniqid()));

    $memberships = app(Memberships::class);
    $memberships->add($lax->id, $subject->id, MembershipRole::Member);
    $memberships->add($strict->id, $subject->id, MembershipRole::Member);

    app(AuthPolicies::class)->setForOrganization($strict->id, new AuthPolicy(sso: SsoEnforcement::Required));

    expect(app(PlatformAuth::class)->attemptPassword(
        Request::create('/login', 'POST'),
        'multi@acme.test',
        'a-strong-unbreached-passphrase',
    ))->toBe(AttemptOutcome::SsoRequired);
});

it('saves the environment baseline from the console and shows what each org gets', function (): void {
    platformRootEnvironment();
    // The environment console is `/admin`, which 404s unless the deployment is
    // multi-tenant — the page is reached by REQUEST now rather than driven directly.
    multiTenantDeployment();

    $r = app(TenantProvisioner::class)->provision(
        new TenantBlueprint(
            organizationName: 'Acme',
            ownerEmail: 'policy-owner@acme.example',
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        )
    );

    serveOnTestHost($r->environment);
    app(EnvironmentContext::class)
        ->set(GenericEnvironment::of($r->environment->id));
    actAsEnvironmentAdmin($r->owner->id, $r->environment->id);

    $org = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-'.uniqid()));

    $current = (array) $this->get(route('environment.auth-policy'))->assertOk()->inertiaProps('policy');

    $this->from(route('environment.auth-policy'))
        ->put(route('environment.auth-policy.update'), [
            ...$current,
            'minLength' => 18,
            'mfa' => 'required',
            'sso' => 'preferred',
            'reuseHistory' => 3,
        ])
        ->assertRedirect(route('environment.auth-policy'))
        ->assertSessionHasNoErrors();

    $effective = app(AuthPolicies::class)->resolve($org->id);

    // Saved, and inherited by an organization that set no override of its own.
    expect($effective->minLength)->toBe(18)
        ->and($effective->mfa->value)->toBe('required')
        ->and($effective->sso->value)->toBe('preferred')
        ->and($effective->reuseHistory)->toBe(3);
});

it('refuses the sign-in rules page to a member without the env-admin capability', function (): void {
    platformRootEnvironment();
    // The environment console is `/admin`, which 404s unless the deployment is
    // multi-tenant — the page is reached by REQUEST now rather than driven directly.
    multiTenantDeployment();

    $r = app(TenantProvisioner::class)->provision(
        new TenantBlueprint(
            organizationName: 'Acme',
            ownerEmail: 'policy-owner2@acme.example',
            ownerName: 'Owner',
            ownerPassword: 'a-strong-unbreached-passphrase',
        )
    );

    serveOnTestHost($r->environment);
    app(EnvironmentContext::class)
        ->set(GenericEnvironment::of($r->environment->id));

    $members = app(Memberships::class);
    [$viewer, $viewerSubjectId] = addMember($r->organization->id, MembershipRole::Viewer, 'policy-viewer@acme.example');

    actAsEnvironmentAdmin($viewer->user_id, $r->environment->id);

    /*
     * REFUSED AT THE DOOR, which is a redirect rather than a 403 — and that is the
     * console's own answer rather than a weaker one.
     *
     * `AuthenticateEnvironmentAdmin` turns away a session that does not hold the capability
     * before the page is reached at all, and sends it somewhere it can be. The
     * component-level test this replaces never met that middleware, so it saw the
     * controller's 403; asking over HTTP asks the whole stack, and the whole stack answers
     * first.
     *
     * WHERE it sends them is that middleware's business and changes with the deployment
     * shape, so what is asserted is the property this test is about: they do not get the
     * page.
     */
    $response = $this->get(route('environment.auth-policy'));

    expect($response->status())->toBe(302)
        ->and((string) $response->headers->get('Location'))->not->toContain('/sign-in-rules');
});

/**
 * "NOT OFFERED" NOW MEANS NOT OFFERED.
 *
 * The auth-policy screen offers three settings for a second factor and enforced two of
 * them. `MfaRequirement::Off` appeared once in the entire codebase — its own declaration
 * — so it behaved exactly like `Optional`: the enrolment panel rendered for everybody and
 * everybody could enrol. That page carries a comment promising every control on it is
 * live, which had quietly stopped being true.
 *
 * Both halves are asserted, because only one of them is a control: the panel not being
 * drawn is a styling decision, and `enable()` refusing is the rule.
 */
it('neither offers nor accepts second-factor enrolment when the policy turns it off', function (): void {
    actingAsRole(MembershipRole::Owner);

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Off));

    // Both halves, because only one of them is a control: the panel not being drawn is a
    // styling decision, and the ENROLMENT ROUTE refusing is the rule. The page draws its
    // sentence from this flag and from nothing else.
    expect(accountSecurity()['twoFactor']['offered'])->toBeFalse();

    app(Sudo::class)->confirm();

    beginMfaEnrolment()->assertForbidden();
});

it('still offers it when the policy leaves it optional', function (): void {
    actingAsRole(MembershipRole::Owner);

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Optional));

    expect(accountSecurity()['twoFactor']['offered'])->toBeTrue();

    app(Sudo::class)->confirm();

    beginMfaEnrolment()->assertSessionHasNoErrors();

    // …and the secret really was minted, or "offered" is a flag nothing acts on.
    expect(flashed('mfaSecret'))->toBeString();
});
