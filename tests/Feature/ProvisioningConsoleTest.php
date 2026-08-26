<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\AuthScheme;
use Cbox\Id\Provisioning\Enums\ConnectionStatus;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function provAdmin(MembershipRole $role = MembershipRole::Owner): string
{
    $subject = app(Subjects::class)->create('prov@acme.test', 'Prov Admin', 'supersecret123');
    // VERIFIED, because that is what an established admin of an established organization
    // IS — the same reasoning `actingAsRole()` states and applies by default. An
    // unverified fixture quietly exercises the unverified-account rules instead of the
    // page under test, and then the fixture gets blamed rather than the rule.
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-prov'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $org->id;
}

/** Register a connection straight through the service, bypassing the console. */
function provConnection(?string $organizationId, string $name = 'Downstream'): ProvisioningConnection
{
    return app(ProvisioningConnections::class)->register(
        $organizationId,
        $name,
        'https://scim.example.test/v2',
        AuthScheme::Bearer,
        'tok_123',
    )->connection;
}

it('registers a provisioning connection', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = provAdmin();

    registerOutboundSync()->assertSessionHasNoErrors();

    expect(ProvisioningConnection::query()->where('organization_id', $orgId)->exists())->toBeTrue();
});

it('pauses a connection', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = provAdmin();
    $connection = provConnection($orgId);

    test()->from(route('provisioning.show', $connection->id))
        ->post(route('provisioning.toggle', $connection->id))
        ->assertRedirect(route('provisioning.show', $connection->id));

    expect($connection->fresh()->status)->toBe(ConnectionStatus::Paused);
});

it('resumes and deletes a connection from the organization plane', function (): void {
    // Both actions existed on the environment plane alone, so a tenant administrator
    // who paused a connection had no way to start it again from their own console.
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = provAdmin();
    $connection = provConnection($orgId);

    $from = route('provisioning.show', $connection->id);

    // Twice, because the endpoint reads the state it is moving FROM off the record — one
    // call would pass on an implementation that only ever paused.
    test()->from($from)->post(route('provisioning.toggle', $connection->id))->assertRedirect($from);
    expect($connection->fresh()->status)->toBe(ConnectionStatus::Paused);

    test()->from($from)->post(route('provisioning.toggle', $connection->id))->assertRedirect($from);
    expect($connection->fresh()->status)->toBe(ConnectionStatus::Active);

    test()->delete(route('provisioning.destroy', $connection->id))
        ->assertRedirect(route('provisioning'));

    expect(ProvisioningConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

it('hides another organization\'s connection from the detail page', function (): void {
    // The environment plane's detail page resolved on the primary key alone, which was
    // safe while only an environment administrator could open it. The same component now
    // answers on the organization plane, where that would be pause, resume and DELETE
    // over every connection in the environment.
    config(['cbox-id.provisioning.verify_url' => false]);
    provAdmin();

    $otherOrg = app(Organizations::class)->create(new NewOrganization('Rival', 'rival-prov'));
    $theirs = provConnection($otherOrg->id, 'Rival downstream');

    test()->get(route('provisioning.show', $theirs->id))->assertNotFound();

    // And every mutation resolves the id inside the same fence rather than trusting it.
    test()->post(route('provisioning.toggle', $theirs->id))->assertNotFound();
    test()->delete(route('provisioning.destroy', $theirs->id))->assertNotFound();

    expect(ProvisioningConnection::query()->whereKey($theirs->id)->exists())->toBeTrue()
        ->and($theirs->fresh()->status)->toBe(ConnectionStatus::Active);
});

it('hides an environment-wide connection from the detail page', function (): void {
    // Environment-wide coverage is a platform capability: the connection receives every
    // subject in the environment, so a tenant administrator must not be able to pause or
    // delete one.
    config(['cbox-id.provisioning.verify_url' => false]);
    provAdmin();

    $platformWide = provConnection(null, 'Env-wide');

    test()->get(route('provisioning.show', $platformWide->id))->assertNotFound();
    test()->post(route('provisioning.toggle', $platformWide->id))->assertNotFound();
    test()->delete(route('provisioning.destroy', $platformWide->id))->assertNotFound();

    expect(ProvisioningConnection::query()->whereKey($platformWide->id)->exists())->toBeTrue();
});

it('refuses to register an environment-wide connection from the organization plane', function (): void {
    // The checkbox is not rendered for a tenant administrator, which is not the guard —
    // a Livewire property is client-settable, and this one decides whether the new
    // connection receives every tenant's people over SCIM.
    config(['cbox-id.provisioning.verify_url' => false]);
    provAdmin();

    registerOutboundSync(['name' => 'Everything', 'environmentWide' => true])->assertForbidden();

    expect(ProvisioningConnection::query()->whereNull('organization_id')->exists())->toBeFalse();
})->group('security');

it('lists only the acting organization\'s connections', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = provAdmin();
    provConnection($orgId, 'Mine');

    $otherOrg = app(Organizations::class)->create(new NewOrganization('Rival', 'rival-list'));
    provConnection($otherOrg->id, 'Theirs');
    provConnection(null, 'Env-wide');

    test()->get(route('provisioning'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'connections',
            fn (Collection $rows): bool => $rows->pluck('name')->all() === ['Mine'],
        ));
})->group('security');

it('forbids a non-admin member', function (): void {
    provAdmin(MembershipRole::Member);

    test()->get(route('provisioning'))->assertForbidden();
});

/**
 * "OAUTH2 CLIENT CREDENTIALS" WAS OFFERED WITH NOWHERE TO PUT THE CREDENTIALS.
 *
 * The dropdown had the option, the docs advertised it, and `HttpScimClient` reads
 * `token_url`, `client_id` and `scope` out of `auth_config` — which this form never wrote,
 * because it called `register()` with five positional arguments and let `$authConfig`
 * default to `[]`. There is no edit form either, so nothing anywhere in the product could
 * supply them.
 *
 * The connection saved Active and every push then failed on an empty token URL, was
 * classified retryable, and backed off and retried until the operation was exhausted.
 * Nobody was provisioned and nobody was deprovisioned.
 */
it('stores what an OAuth2 client-credentials target needs to authenticate', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = provAdmin();

    registerOutboundSync([
        'scheme' => 'oauth2_client_credentials',
        'tokenUrl' => 'https://scim.example.test/oauth/token',
        'clientId' => 'scim-provisioner',
        'scope' => 'scim:write',
        'secret' => 'cs_123',
    ])->assertSessionHasNoErrors();

    $connection = ProvisioningConnection::query()->where('organization_id', $orgId)->firstOrFail();

    expect($connection->auth_config['token_url'])->toBe('https://scim.example.test/oauth/token')
        ->and($connection->auth_config['client_id'])->toBe('scim-provisioner')
        ->and($connection->auth_config['scope'])->toBe('scim:write');
});

it('refuses to register a client-credentials target with nowhere to fetch a token', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    provAdmin();

    // The alternative is what shipped: an Active connection that can never complete a
    // single push, failing on a schedule, with no field anywhere to correct it.
    registerOutboundSync([
        'scheme' => 'oauth2_client_credentials',
        'secret' => 'cs_123',
    ])->assertSessionHasErrors(['tokenUrl', 'clientId']);

    expect(ProvisioningConnection::query()->exists())->toBeFalse();
});

/**
 * And a bearer target keeps writing nothing — the fields belong to one scheme, and an
 * `auth_config` on a connection that does not use it is a stored value nothing reads.
 */
it('leaves the auth config empty for a bearer target', function (): void {
    config(['cbox-id.provisioning.verify_url' => false]);
    $orgId = provAdmin();

    registerOutboundSync([
        'name' => 'Bearer one',
        'tokenUrl' => 'https://ignored.example.test/token',
    ])->assertSessionHasNoErrors();

    expect(ProvisioningConnection::query()->where('organization_id', $orgId)->firstOrFail()->auth_config)->toBe([]);
});
