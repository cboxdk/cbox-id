<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Impersonation;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

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
