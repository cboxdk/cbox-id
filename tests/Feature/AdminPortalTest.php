<?php

declare(strict_types=1);

use App\Models\AdminPortalLink;
use App\Platform\AdminPortal;
use App\Platform\Enums\PortalScope;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Testing\FakeAuditLog;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Livewire\Volt\Volt;

// This file is ABOUT the entitlement gate, so it declares the mode it exercises.
// The default is now `open` — an unset entitlement means granted, which is what a
// self-hosted deployment runs and what most of the suite therefore sees. Gating
// only means anything under `metered`, where the billing projection is the sole
// source of a grant.
beforeEach(function (): void {
    config(['cbox-id.entitlements.mode' => 'metered']);
});

// gateAdmin() and grantFeature() are shared helpers defined in EntitlementGateTest.

it('lets an entitled admin generate a setup link, recorded in the audit trail', function () {
    $orgId = gateAdmin('portal-gen');
    grantFeature($orgId, 'cbox-id-sso');

    $fake = new FakeAuditLog;
    app()->instance(AuditLog::class, $fake);

    $component = Volt::test('console.connections.index')->call('invite')->assertHasNoErrors();

    expect($component->get('portalUrl'))->toContain('/setup/');
    expect(AdminPortalLink::query()->where('organization_id', $orgId)->count())->toBe(1);
    $fake->assertRecorded('portal_link.created', fn ($e) => $e->organizationId === $orgId);
});

it('a non-admin cannot reach the invite action even on an entitled org', function () {
    $orgId = gateAdmin('portal-member', MembershipRole::Member);
    grantFeature($orgId, 'cbox-id-sso');

    // The admin read-gate blocks a member at mount — they never reach invite().
    Volt::test('console.connections.index')->assertForbidden();
});

it('opens the portal for a valid token', function () {
    $orgId = gateAdmin('portal-open');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, PortalScope::Sso, 'sub_creator');

    $this->followingRedirects()
        ->get(route('portal.enter', $token))
        ->assertOk()
        ->assertSee('SSO connection');
});

it('regenerates the session id when a setup link is redeemed (anti-fixation)', function () {
    $orgId = gateAdmin('portal-fixation');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, PortalScope::Sso, 'sub_creator');

    session()->start();
    $before = session()->getId();

    expect(app(AdminPortal::class)->redeem($token))->not->toBeNull();

    // A pre-fixed session cookie cannot ride the privilege elevation into the portal.
    expect(session()->getId())->not->toBe($before);
});

it('creates a connection only for the org bound to the portal session', function () {
    $orgA = gateAdmin('portal-a');
    grantFeature($orgA, 'cbox-id-sso');
    // A second, different org. gateAdmin leaves this one as the "current" user —
    // proving the portal ignores CurrentUser and uses only the bound session org.
    $orgB = gateAdmin('portal-b');
    grantFeature($orgB, 'cbox-id-sso');

    $token = app(AdminPortal::class)->generate($orgA, PortalScope::Sso, 'sub_creator');
    expect(app(AdminPortal::class)->redeem($token))->not->toBeNull();

    Volt::test('portal.setup')
        ->set('type', 'saml')
        ->set('connName', 'Bound Co')
        ->set('idp_entity_id', 'https://idp.corp/metadata')
        ->set('idp_sso_url', 'https://idp.corp/sso')
        ->set('idp_x509cert', '-----BEGIN CERTIFICATE-----MIIB-----END CERTIFICATE-----')
        ->set('sp_entity_id', 'https://sp.acme/metadata')
        ->set('sp_acs_url', 'https://sp.acme/acs')
        ->call('createConnection')
        ->assertHasNoErrors();

    expect(Connection::query()->where('organization_id', $orgA)->where('name', 'Bound Co')->exists())->toBeTrue()
        ->and(Connection::query()->where('organization_id', $orgB)->exists())->toBeFalse();
});

it('lets the IT admin verify their domain from the self-serve portal, bound to the right org', function () {
    $orgA = gateAdmin('portal-dom-a');
    grantFeature($orgA, 'cbox-id-sso');
    $orgB = gateAdmin('portal-dom-b');

    $token = app(AdminPortal::class)->generate($orgA, PortalScope::Sso, 'sub_creator');
    expect(app(AdminPortal::class)->redeem($token))->not->toBeNull();

    $component = Volt::test('portal.setup')
        ->set('domain', 'acme.com')
        ->call('addDomain')
        ->assertHasNoErrors();

    // The DNS challenge is surfaced and the domain is bound to the portal's org only.
    expect($component->get('dnsToken'))->not->toBeNull()
        ->and(VerifiedDomain::query()->where('organization_id', $orgA)->where('domain', 'acme.com')->exists())->toBeTrue()
        ->and(VerifiedDomain::query()->where('organization_id', $orgB)->exists())->toBeFalse();
});

it('a portal session grants no access to the platform console', function () {
    $orgId = gateAdmin('portal-iso');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, PortalScope::Sso, 'sub_creator');
    $link = AdminPortalLink::query()->where('organization_id', $orgId)->firstOrFail();

    $this->withSession([
        AdminPortal::SESSION_KEY => [
            'link_id' => $link->id,
            'org' => $orgId,
            'scope' => 'sso',
            'expires' => now()->addMinutes(10)->getTimestamp(),
        ],
    ])->get('/dashboard')->assertRedirect(route('login'));
});

it('refuses an expired token at the entry point', function () {
    $orgId = gateAdmin('portal-exp');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, PortalScope::Sso, 'sub_creator');

    AdminPortalLink::query()->where('organization_id', $orgId)->update(['expires_at' => now()->subMinute()]);

    $this->get(route('portal.enter', $token))->assertStatus(410);
});

it('refuses a consumed token at the entry point', function () {
    $orgId = gateAdmin('portal-consumed');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, PortalScope::Sso, 'sub_creator');

    AdminPortalLink::query()->where('organization_id', $orgId)->update(['consumed_at' => now()]);

    $this->get(route('portal.enter', $token))->assertStatus(410);
});

it('refuses redemption when the org is no longer entitled', function () {
    $orgId = gateAdmin('portal-lapse');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, PortalScope::Sso, 'sub_creator');

    app(EntitlementWriter::class)->revoke($orgId, 'cbox-id-sso', EntitlementSource::Manual);

    $this->get(route('portal.enter', $token))->assertStatus(410);
    expect(app(AdminPortal::class)->redeem($token))->toBeNull();
});

it('finishing marks the link consumed, records completion, and closes the session', function () {
    $orgId = gateAdmin('portal-finish');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, PortalScope::Sso, 'sub_creator');

    $fake = new FakeAuditLog;
    app()->instance(AuditLog::class, $fake);

    app(AdminPortal::class)->redeem($token);
    Volt::test('portal.setup')->call('finish')->assertOk();

    $link = AdminPortalLink::query()->where('organization_id', $orgId)->firstOrFail();
    expect($link->consumed_at)->not->toBeNull();
    $fake->assertRecorded('portal_link.completed');

    // A consumed link is no longer redeemable.
    expect(app(AdminPortal::class)->redeem($token))->toBeNull();
});

it('the setup screen redirects to the expired page without a portal session', function () {
    $this->get(route('portal.setup'))->assertRedirect(route('portal.expired'));
});

/**
 * The link is matched on its token hash alone, so without the model's environment scope
 * the redemption route was a CROSS-ENVIRONMENT primitive: hand a link to the operator of
 * any other environment and they could redeem it on their own host, where the connection,
 * verified domain or SCIM directory it creates is stamped with THEIR environment.
 */
it('refuses a setup link minted in another environment, on the service and over HTTP', function () {
    $envA = Environment::query()->create([
        'name' => 'Env A', 'slug' => 'portal-xenv-a', 'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active, 'is_default' => false, 'settings' => [],
    ]);
    $envB = Environment::query()->create([
        'name' => 'Env B', 'slug' => 'portal-xenv-b', 'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active, 'is_default' => false, 'settings' => [],
    ]);

    // Env B owns the host this test's requests arrive on — the attacker's own IdP.
    serveOnTestHost($envB);

    // The victim mints a link on env A.
    app(EnvironmentContext::class)->set($envA);
    $orgId = gateAdmin('portal-xenv');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, PortalScope::Sso, 'sub_creator');

    // On env B the link does not EXIST — the hard scope removes it from the token-hash
    // lookup redemption is built on, so nothing downstream (the entitlement re-gate, the
    // session's org pivot) has to hold for the refusal. That matters: the entitlement
    // re-gate was bypassable on its own, via a cross-environment cache hit.
    app(EnvironmentContext::class)->set($envB);

    expect(AdminPortalLink::query()->where('token_hash', hash('sha256', $token))->exists())->toBeFalse()
        ->and(app(AdminPortal::class)->redeem($token))->toBeNull()
        ->and(session()->has(AdminPortal::SESSION_KEY))->toBeFalse();

    // Over HTTP the host resolves to env B, so the entry point refuses it there too.
    $this->get(route('portal.enter', $token))->assertStatus(410);

    // The link is untouched on the environment that issued it.
    app(EnvironmentContext::class)->set($envA);
    expect(app(AdminPortal::class)->redeem($token))->not->toBeNull();
});

it('is single-use: a token cannot be redeemed twice (R7)', function () {
    $orgId = gateAdmin('portal-single-use');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, PortalScope::Sso, 'sub_creator');

    // First redemption succeeds and burns the link; a leaked/re-opened URL fails.
    expect(app(AdminPortal::class)->redeem($token))->not->toBeNull()
        ->and(app(AdminPortal::class)->redeem($token))->toBeNull();
});
