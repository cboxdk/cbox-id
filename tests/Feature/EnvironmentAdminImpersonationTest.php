<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use App\Platform\Impersonation;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * An environment-admin session is TWO things: the ordinary subject session, and the
 * ANCHOR naming the one environment it administers. Impersonation has to put both aside
 * and bring both back, and the second is the half that is easy to lose.
 *
 * Losing the identity is loud — the admin comes back to a sign-in. Losing the anchor is
 * silent: the console still opens, because the person really is an admin of that
 * environment, and the anti-bleed check that is supposed to confine them to ONE host is
 * satisfied by nothing at all. That is the property this file exists for.
 *
 * @return array{member: AccountMember, env: Environment, envId: string, orgId: string, userId: string}
 */
function envAdminImpersonationSetup(): array
{
    // The `/admin` prefix carries `multi.tenant` and 404s on a single-tenant deployment.
    multiTenantDeployment();
    platformRootEnvironment();

    $provisioned = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
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
        'member' => $provisioned->member,
        'env' => $env,
        'envId' => $env->id,
        'orgId' => $orgId,
        'userId' => $userId,
    ];
}

it('takes the environment anchor away for the duration, and gives exactly it back', function (): void {
    ['member' => $member, 'envId' => $envId, 'orgId' => $orgId, 'userId' => $userId] = envAdminImpersonationSetup();

    app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));
    actAsEnvironmentAdmin($member, $envId);

    expect(app(EnvironmentAdminAuth::class)->check())
        ->toBeTrue('fixture: this is meant to BE an environment admin before it steps into anyone');

    $this->post(route('environment.impersonate', $userId), ['organization' => $orgId, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));

    // The anchor is gone from the browser, not merely outranked by the victim's session.
    expect(session(Impersonation::SESSION_KEY))->not->toBeNull()
        ->and(session(EnvironmentAdminAuth::ENV_KEY))->toBeNull();

    // …and the control plane is genuinely shut, asked fresh through the real gate rather
    // than by re-reading the key we just asserted on. The refusal is the SaaS pull flow —
    // "go authenticate at the account host and hand off back here" — which is what an
    // unauthenticated visitor to a tenant's admin console gets.
    nextRequest();
    $this->get(route('environment.home'))
        ->assertRedirect('https://cboxid.com'.route('environment.open', $envId, false));

    $this->post(route('impersonation.exit'));

    // Back, and back to the SAME environment — an admin who returned unbound would find
    // the console open with nothing confining them to one host.
    expect(session(EnvironmentAdminAuth::ENV_KEY))->toBe($envId);

    nextRequest();
    $this->get(route('environment.home'))->assertOk();
})->group('security');

/**
 * The anchor is not an identity, so restoring it must not be able to CREATE one.
 *
 * A member removed, unlinked, or whose session was revoked during the window restores
 * nothing — and if nothing came back, the anchor must not come back either. An anchor
 * sitting in a browser nobody is signed into is not an authorization on its own, but it
 * is exactly the half-restored state the exit path exists to avoid, and the next person
 * to sign in on that browser inherits it.
 */
it('restores no anchor when the acting admin does not come back', function (): void {
    ['member' => $member, 'envId' => $envId, 'orgId' => $orgId, 'userId' => $userId] = envAdminImpersonationSetup();

    app(EnvironmentContext::class)->set(GenericEnvironment::of($envId));
    actAsEnvironmentAdmin($member, $envId);

    $this->post(route('environment.impersonate', $userId), ['organization' => $orgId, 'reason' => IMPERSONATION_REASON])
        ->assertRedirect(route('dashboard'));

    // The acting admin's membership disappears mid-window: exit resolves the member id
    // captured at start and finds nothing to resolve it to.
    $member->delete();

    $this->post(route('impersonation.exit'));

    expect(session(EnvironmentAdminAuth::ENV_KEY))->toBeNull()
        ->and(app(EnvironmentAdminAuth::class)->check())->toBeFalse();
})->group('security');
