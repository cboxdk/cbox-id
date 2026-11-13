<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Impersonation;
use App\Platform\PlatformAuth;
use App\Platform\StepUpPlane;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Enums\MembershipStatus;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\SamlIdp\Testing\InteractsWithSamlIdp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class, InteractsWithSamlIdp::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * The environment console is on the app host; the person an administrator steps into signs
 * in on the environment's own host, which shares no cookie with it. So an impersonation
 * starts THERE, through a signed, single-use link the console hands out, and the
 * administrator's own app-host session is never put aside at all.
 *
 * Losing the identity is loud — the admin comes back to a sign-in. Losing the anchor is
 * silent: the console still opens, because the person really is an admin of that
 * environment, and the anti-bleed check that is supposed to confine them to ONE host is
 * satisfied by nothing at all. That is the property this file exists for.
 *
 * @return array{member: Membership, env: Environment, envId: string, orgId: string, userId: string}
 */
function envAdminImpersonationSetup(): array
{
    // The `/admin` prefix carries `multi.tenant` and 404s on a single-tenant deployment.
    multiTenantDeployment();
    platformRootEnvironment();

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    // Reach the environment the way its admin does: on its OWN host. An unmapped host
    // resolves to the platform ROOT, which is where the account plane lives.
    $env = serveOnTestHost($provisioned->environment);

    // Somebody to step into — an ordinary Member of an organization INSIDE the
    // environment, because owners and admins are refused outright.
    [$orgId, $userId] = app(EnvironmentContext::class)->runAs($env, function (): array {
        $org = app(Organizations::class)->create(new NewOrganization('Tenant Co', 'tenant-co'));
        $subject = app(Subjects::class)->create('victim@tenant.test', 'Victim', 'a-strong-unbreached-passphrase');
        app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);

        return [$org->id, $subject->id];
    });

    return [
        'member' => $provisioned->membership, 'subjectId' => $provisioned->owner->id,
        'env' => $env,
        'envId' => $env->id,
        'orgId' => $orgId,
        'userId' => $userId,
    ];
}

it('starts the impersonation on the environment\'s own host, through a signed single-use link', function (): void {
    ['subjectId' => $admin, 'env' => $env, 'envId' => $envId, 'orgId' => $orgId, 'userId' => $userId] = envAdminImpersonationSetup();

    app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));
    actAsEnvironmentAdmin($admin, $envId);
    confirmEnvironmentStepUp();
    $console = (string) session(PlatformAuth::SESSION_KEY);

    expect(app(EnvironmentAdminAuth::class)->check())
        ->toBeTrue('fixture: this is meant to BE an environment admin before it steps into anyone');

    $start = $this->post(route('environment.impersonate', $userId), ['organization' => $orgId, 'reason' => IMPERSONATION_REASON]);
    $launch = (string) $start->headers->get('Location');

    // Nothing has happened on the app host: the administrator is still themselves there.
    expect(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and(session(PlatformAuth::SESSION_KEY))->toBe($console)
        ->and(parse_url($launch, PHP_URL_PATH))->toBe('/impersonation/launch');

    // On the environment's host, the link starts it and lands on the person's console.
    backOnTestHost();
    $this->get($launch)->assertRedirect(route('dashboard'))->assertHeader('Referrer-Policy', 'no-referrer');
    expect(session(Impersonation::SESSION_KEY))->not->toBeNull();

    // Spent: the same link a second time starts nothing.
    $this->get($launch)->assertNotFound();

    // Exit goes back to the console it was started from, on the app host.
    $back = (string) $this->post(route('impersonation.exit'))->assertRedirect()->headers->get('Location');
    expect(parse_url($back, PHP_URL_HOST))->toBe(config('cbox-id.tenancy.account_host'))
        ->and(parse_url($back, PHP_URL_PATH))->toBe('/'.app(EnvironmentConsolePath::class)->for($envId))
        ->and(session(Impersonation::SESSION_KEY))->toBeNull();
})->group('security');

it('refuses a launch link that was tampered with, or outlived the console that minted it', function (): void {
    ['subjectId' => $admin, 'envId' => $envId, 'orgId' => $orgId, 'userId' => $userId] = envAdminImpersonationSetup();

    actAsEnvironmentAdmin($admin, $envId);
    confirmEnvironmentStepUp();
    $console = (string) session(PlatformAuth::SESSION_KEY);

    $launch = (string) $this->post(route('environment.impersonate', $userId), ['organization' => $orgId, 'reason' => IMPERSONATION_REASON])
        ->headers->get('Location');

    backOnTestHost();

    // Another person named in the link: the signature no longer holds.
    $this->get(str_replace($userId, $admin, $launch))->assertForbidden();

    // The administrator signed out of the console before the link was followed.
    app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->revoke($console));
    $this->get($launch)->assertNotFound();
    expect(session(Impersonation::SESSION_KEY))->toBeNull();
})->group('security');

it('opens a launch link only on the environment it names, for an administrator who still administers it', function (): void {
    ['subjectId' => $admin, 'member' => $member, 'envId' => $envId, 'orgId' => $orgId, 'userId' => $userId] = envAdminImpersonationSetup();

    actAsEnvironmentAdmin($admin, $envId);
    confirmEnvironmentStepUp();

    $mint = fn (): string => (string) $this->post(route('environment.impersonate', $userId), ['organization' => $orgId, 'reason' => IMPERSONATION_REASON])
        ->headers->get('Location');

    $launch = $mint();

    // The administrator's session id stays on the app host: the link carries a ticket.
    parse_str((string) parse_url($launch, PHP_URL_QUERY), $query);
    expect($query)->not->toHaveKey('console')
        ->and($query['environment'] ?? null)->toBe($envId)
        ->and($query['ticket'] ?? null)->toBeString();

    // Replayed on another environment's host, it starts nothing.
    backOnTestHost();
    $other = provisionAccount('owner@other.example')['environment'];
    $other->forceFill(['domain' => 'other-env.test', 'domain_verified_at' => now()])->save();

    $this->get('http://other-env.test'.parse_url($launch, PHP_URL_PATH).'?'.parse_url($launch, PHP_URL_QUERY))->assertNotFound();
    expect(session(Impersonation::SESSION_KEY))->toBeNull();

    // An administrator suspended between minting the link and following it starts nothing.
    serveConsoleOn();
    $launch = $mint();
    app(PlatformRoot::class)->run(fn () => DB::table('memberships')->where('id', $member->id)->update(['status' => MembershipStatus::Suspended->value]));

    backOnTestHost();
    $this->get($launch)->assertNotFound();
    expect(session(Impersonation::SESSION_KEY))->toBeNull();
})->group('security');

/**
 * Audit finding #18. Starting an impersonation is privileged access: the support session
 * beside it already asks for the environment console's step-up, and this one did not, so a
 * stale, unattended or hijacked console session was enough to become any regular member.
 */
it('asks for the environment step-up before stepping into anyone, and starts nothing without it', function (): void {
    ['subjectId' => $admin, 'envId' => $envId, 'orgId' => $orgId, 'userId' => $userId] = envAdminImpersonationSetup();

    actAsEnvironmentAdmin($admin, $envId);

    $start = $this->post(route('environment.impersonate', $userId), ['organization' => $orgId, 'reason' => IMPERSONATION_REASON]);

    // To the step-up, not to a launch link.
    $start->assertRedirect();
    expect(parse_url((string) $start->headers->get('Location'), PHP_URL_PATH))->not->toBe('/impersonation/launch')
        ->and(session(Impersonation::SESSION_KEY))->toBeNull();

    // An Inertia request is answered in place with the step-up dialog's 403.
    $this->post(route('environment.impersonate', $userId), ['organization' => $orgId, 'reason' => IMPERSONATION_REASON], ['X-Inertia' => 'true'])
        ->assertForbidden()
        ->assertJsonPath('step_up.plane', StepUpPlane::Environment->label());

    // With the step-up, the same request is the launch link.
    confirmEnvironmentStepUp();
    $launch = (string) $this->post(route('environment.impersonate', $userId), ['organization' => $orgId, 'reason' => IMPERSONATION_REASON])
        ->headers->get('Location');

    expect(parse_url($launch, PHP_URL_PATH))->toBe('/impersonation/launch');
})->group('security');

/**
 * Audit finding #18, second half. An impersonation is a read-only look at a person. The
 * SAML IdP's HTTP-Redirect binding is a GET, which the method-based read-only guard let
 * through, so the impersonated session could get a signed assertion naming the person for
 * any service provider they reach — a sign-in elsewhere AS them, outside the support
 * session's controls.
 */
it('never issues a SAML assertion for the person being impersonated', function (): void {
    ['subjectId' => $admin, 'env' => $env, 'envId' => $envId, 'orgId' => $orgId, 'userId' => $userId] = envAdminImpersonationSetup();

    $sp = app(EnvironmentContext::class)->runAs($env, function () use ($userId) {
        app(Subjects::class)->markEmailVerified($userId, 'victim@tenant.test');

        return $this->registerSamlServiceProvider();
    });

    actAsEnvironmentAdmin($admin, $envId);
    confirmEnvironmentStepUp();
    followImpersonationLaunch($this->post(route('environment.impersonate', $userId), ['organization' => $orgId, 'reason' => IMPERSONATION_REASON]))
        ->assertRedirect(route('dashboard'));

    expect(app(Impersonation::class)->isImpersonating())->toBeTrue();

    $sso = fn () => $this->get('/sso/saml/idp/sso?'.http_build_query([
        'SAMLRequest' => $this->makeRedirectAuthnRequest($sp->entity_id),
        'RelayState' => 'imp',
    ]));

    $sso()->assertForbidden()->assertDontSee('SAMLResponse', false);

    // Control: the same request from the person's own session is answered with an assertion.
    $this->post(route('impersonation.exit'));
    $own = app(EnvironmentContext::class)->runAs($env, fn () => app(SessionManager::class)->start($userId, $orgId, ['pwd'])->id);
    session([PlatformAuth::SESSION_KEY => $own]);

    $sso()->assertOk()->assertSee('name="SAMLResponse"', false);
})->group('security');

/**
 * Audit finding #22. Impersonating a person who belongs to no organization is a supported
 * branch, and its marker carries no organization. The validator demanded one, so the
 * marker read as "not impersonating" and every guard stood down: writes allowed, no time
 * box, credential routes open, and Exit refused.
 */
it('contains an impersonation of a person with no organization like any other', function (): void {
    ['subjectId' => $admin, 'env' => $env, 'envId' => $envId] = envAdminImpersonationSetup();

    $loneId = app(EnvironmentContext::class)->runAs($env, fn () => app(Subjects::class)->create('lonely@tenant.test', 'Lonely', 'a-strong-unbreached-passphrase')->id);

    actAsEnvironmentAdmin($admin, $envId);
    confirmEnvironmentStepUp();
    followImpersonationLaunch($this->post(route('environment.impersonate', $loneId), ['reason' => IMPERSONATION_REASON]))
        ->assertRedirect(route('dashboard'));

    $marker = app(Impersonation::class)->active();

    expect($marker)->not->toBeNull()
        ->and($marker?->organizationId)->toBeNull()
        ->and($marker?->subject)->toBe($loneId)
        ->and(app(Impersonation::class)->isImpersonating())->toBeTrue();

    // Read-only, and the credential routes closed.
    $this->post(route('device.lookup'), ['user_code' => 'ABCD-EFGH'])->assertForbidden();
    $this->post(route('passkeys.register.options'))->assertForbidden();

    // The time box ends it.
    $this->travel(Impersonation::MAX_MINUTES + 1)->minutes();
    $this->get(route('dashboard'))->assertRedirect();
    expect(session(Impersonation::SESSION_KEY))->toBeNull();
})->group('security');

it('lets the administrator exit an impersonation of a person with no organization', function (): void {
    ['subjectId' => $admin, 'env' => $env, 'envId' => $envId] = envAdminImpersonationSetup();

    $loneId = app(EnvironmentContext::class)->runAs($env, fn () => app(Subjects::class)->create('lonely@tenant.test', 'Lonely', 'a-strong-unbreached-passphrase')->id);

    actAsEnvironmentAdmin($admin, $envId);
    confirmEnvironmentStepUp();
    followImpersonationLaunch($this->post(route('environment.impersonate', $loneId), ['reason' => IMPERSONATION_REASON]));

    $subjectSession = (string) session(PlatformAuth::SESSION_KEY);

    $back = (string) $this->post(route('impersonation.exit'))->assertRedirect()->headers->get('Location');

    expect(parse_url($back, PHP_URL_PATH))->toBe('/'.app(EnvironmentConsolePath::class)->for($envId))
        ->and(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and(app(EnvironmentContext::class)->runAs($env, fn () => app(SessionManager::class)->active($subjectSession)))->toBeNull();
})->group('security');

/**
 * Fail closed: a marker that is there but does not parse is still an impersonation. The
 * guards stay up, and Exit ends it rather than answering 403 and leaving the browser as
 * the person.
 */
it('treats an unreadable impersonation marker as an impersonation, and still ends it', function (): void {
    ['env' => $env, 'orgId' => $orgId, 'userId' => $userId] = envAdminImpersonationSetup();

    backOnTestHost();
    $session = app(EnvironmentContext::class)->runAs($env, fn () => app(SessionManager::class)->start($userId, $orgId, ['impersonation'])->id);
    session([PlatformAuth::SESSION_KEY => $session, Impersonation::SESSION_KEY => ['subject' => $userId]]);

    expect(app(Impersonation::class)->active())->toBeNull()
        ->and(app(Impersonation::class)->isImpersonating())->toBeTrue();

    $this->post(route('impersonation.exit'))->assertRedirect(route('login'));

    expect(session(Impersonation::SESSION_KEY))->toBeNull()
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull()
        ->and(app(EnvironmentContext::class)->runAs($env, fn () => app(SessionManager::class)->active($session)))->toBeNull();
})->group('security');
