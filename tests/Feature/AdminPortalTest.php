<?php

declare(strict_types=1);

use App\Models\AdminPortalLink;
use App\Platform\AdminPortal;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Testing\FakeAuditLog;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Livewire\Volt\Volt;

// gateAdmin() and grantFeature() are shared helpers defined in EntitlementGateTest.

it('lets an entitled admin generate a setup link, recorded in the audit trail', function () {
    $orgId = gateAdmin('portal-gen');
    grantFeature($orgId, 'cbox-id-sso');

    $fake = new FakeAuditLog;
    app()->instance(AuditLog::class, $fake);

    $component = Volt::test('connections')->call('invite')->assertHasNoErrors();

    expect($component->get('portalUrl'))->toContain('/setup/');
    expect(AdminPortalLink::query()->where('organization_id', $orgId)->count())->toBe(1);
    $fake->assertRecorded('portal_link.created', fn ($e) => $e->organizationId === $orgId);
});

it('a non-admin cannot reach the invite action even on an entitled org', function () {
    $orgId = gateAdmin('portal-member', 'member');
    grantFeature($orgId, 'cbox-id-sso');

    // The admin read-gate blocks a member at mount — they never reach invite().
    Volt::test('connections')->assertForbidden();
});

it('opens the portal for a valid token', function () {
    $orgId = gateAdmin('portal-open');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, 'sso', 'sub_creator');

    $this->followingRedirects()
        ->get(route('portal.enter', $token))
        ->assertOk()
        ->assertSee('SSO connection');
});

it('creates a connection only for the org bound to the portal session', function () {
    $orgA = gateAdmin('portal-a');
    grantFeature($orgA, 'cbox-id-sso');
    // A second, different org. gateAdmin leaves this one as the "current" user —
    // proving the portal ignores CurrentUser and uses only the bound session org.
    $orgB = gateAdmin('portal-b');
    grantFeature($orgB, 'cbox-id-sso');

    $token = app(AdminPortal::class)->generate($orgA, 'sso', 'sub_creator');
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

it('a portal session grants no access to the platform console', function () {
    $orgId = gateAdmin('portal-iso');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, 'sso', 'sub_creator');
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
    $token = app(AdminPortal::class)->generate($orgId, 'sso', 'sub_creator');

    AdminPortalLink::query()->where('organization_id', $orgId)->update(['expires_at' => now()->subMinute()]);

    $this->get(route('portal.enter', $token))->assertStatus(410);
});

it('refuses a consumed token at the entry point', function () {
    $orgId = gateAdmin('portal-consumed');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, 'sso', 'sub_creator');

    AdminPortalLink::query()->where('organization_id', $orgId)->update(['consumed_at' => now()]);

    $this->get(route('portal.enter', $token))->assertStatus(410);
});

it('refuses redemption when the org is no longer entitled', function () {
    $orgId = gateAdmin('portal-lapse');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, 'sso', 'sub_creator');

    app(EntitlementWriter::class)->revoke($orgId, 'cbox-id-sso', EntitlementSource::Manual);

    $this->get(route('portal.enter', $token))->assertStatus(410);
    expect(app(AdminPortal::class)->redeem($token))->toBeNull();
});

it('finishing marks the link consumed, records completion, and closes the session', function () {
    $orgId = gateAdmin('portal-finish');
    grantFeature($orgId, 'cbox-id-sso');
    $token = app(AdminPortal::class)->generate($orgId, 'sso', 'sub_creator');

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
