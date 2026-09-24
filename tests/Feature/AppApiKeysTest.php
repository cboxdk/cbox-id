<?php

declare(strict_types=1);

use App\Platform\ApiKeys\BoundApiKeys;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\TenantContext;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\ApiKeyPrefix;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * API KEYS FOR THE APPS BUILT ON AN ENVIRONMENT — the hosted pages over the framework's
 * customer API keys: a person's own keys under My account, every key in an organization
 * for its administrators, and the same list on the environment console's organization page.
 *
 * The properties that matter, each asserted with its reason rather than its class:
 * a key carries only what its holder holds (and a refusal SAYS which permission), an app
 * owned by another organization is not offered, nobody reaches a key that is not theirs to
 * reach, and `return_to` only ever points at an origin the app registered.
 */

/**
 * @param  array<string, mixed>  $changes
 */
function createAppKey(array $fixture, array $changes = []): TestResponse
{
    return test()->from(route('account.api-keys'))->post(route('account.api-keys.store'), [
        'client_id' => $fixture['clientId'],
        'organization_id' => $fixture['org']->id,
        'name' => 'Accounting sync',
        'permissions' => ['returns:read'],
        'expires' => 'never',
        ...$changes,
    ]);
}

function keyIsRevoked(string $keyId): bool
{
    return app(CustomerApiKeys::class)->find($keyId)?->revoked_at !== null;
}

// ── The holder's page ─────────────────────────────────────────────────────────

it('offers the apps with a prefix and only the permissions the person holds, described', function (): void {
    $fixture = appKeyFixture();
    signInKeyHolder($fixture['ada'], $fixture['org']);

    $page = $this->get(route('account.api-keys'))->assertOk()->inertiaProps();

    expect($page['help']['topic'])->toBe('api-keys')
        ->and($page['organizationId'])->toBe($fixture['org']->id)
        ->and($page['apps'])->toHaveCount(1)
        ->and($page['apps'][0]['name'])->toBe('Acme Tax')
        ->and($page['apps'][0]['prefix'])->toBe('ctax_live')
        // The Owner role's `settings:manage` is the app's, but not Ada's — never offered.
        ->and($page['apps'][0]['permissions'])->toBe([
            ['name' => 'returns:file', 'description' => 'File a tax return'],
            ['name' => 'returns:read', 'description' => 'See your tax returns'],
        ])
        // The only app there is, preselected.
        ->and($page['selectedClientId'])->toBe($fixture['clientId']);
});

it('preselects the app an SDK deep link names and offers a way back to its own origin', function (): void {
    $fixture = appKeyFixture();
    signInKeyHolder($fixture['ada'], $fixture['org']);

    $page = $this->get(route('account.api-keys', [
        'client_id' => $fixture['clientId'],
        'return_to' => 'https://tax.example/settings/api',
    ]))->assertOk()->inertiaProps();

    expect($page['selectedClientId'])->toBe($fixture['clientId'])
        ->and($page['requestedAppUnavailable'])->toBeFalse()
        ->and($page['returnTo'])->toBe(['url' => 'https://tax.example/settings/api', 'appName' => 'Acme Tax']);
});

it('never offers a way back to anywhere the app did not register', function (?string $clientId, string $returnTo): void {
    $fixture = appKeyFixture();
    signInKeyHolder($fixture['ada'], $fixture['org']);

    // Another app in the environment registered evil.example — which makes it a host this
    // environment redirects to, and still not an origin THIS app registered.
    app(ClientRegistry::class)->register(new NewClient('Other', redirectUris: ['https://evil.example/cb']));

    $query = ['return_to' => $returnTo];

    if ($clientId === 'fixture') {
        $query['client_id'] = $fixture['clientId'];
    }

    expect($this->get(route('account.api-keys', $query))->assertOk()->inertiaProps('returnTo'))->toBeNull();
})->with([
    'another app\'s origin' => ['fixture', 'https://evil.example/phish'],
    'a look-alike host' => ['fixture', 'https://tax.example.evil.test/'],
    'credentials in the authority' => ['fixture', 'https://tax.example@evil.example/'],
    'plain http' => ['fixture', 'http://tax.example/settings'],
    'a script' => ['fixture', 'javascript:alert(1)'],
    'no app named at all' => [null, 'https://tax.example/settings'],
]);

it('says so when the linked app offers no keys to this organization', function (): void {
    $fixture = appKeyFixture();
    $elsewhere = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-keys'));
    $foreign = app(ClientRegistry::class)->register(new NewClient('Globex App', redirectUris: ['https://globex.example/cb'], organizationId: $elsewhere->id));
    app(CustomerApiKeys::class)->setPrefix($foreign->client->client_id, ApiKeyPrefix::of('globex_live'));

    signInKeyHolder($fixture['ada'], $fixture['org']);

    $page = $this->get(route('account.api-keys', [
        'client_id' => $foreign->client->client_id,
        'return_to' => 'https://globex.example/back',
    ]))->assertOk()->inertiaProps();

    // Another organization's app: not listed, not preselected, no way back to it.
    expect(array_column($page['apps'], 'clientId'))->toBe([$fixture['clientId']])
        ->and($page['requestedAppUnavailable'])->toBeTrue()
        ->and($page['returnTo'])->toBeNull();
});

it('lists only organizations the person is an active member of, whatever the query names', function (): void {
    $fixture = appKeyFixture();
    $stranger = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-stranger'));

    signInKeyHolder($fixture['ada'], $fixture['org']);

    $page = $this->get(route('account.api-keys', ['organization' => $stranger->id]))->assertOk()->inertiaProps();

    expect($page['organizationId'])->toBe($fixture['org']->id)
        ->and(array_column($page['organizations'], 'id'))->toBe([$fixture['org']->id]);
});

it('creates a key, shows it once, lists it, and writes it to the organization\'s activity log', function (): void {
    $fixture = appKeyFixture();
    signInKeyHolder($fixture['ada'], $fixture['org']);

    createAppKey($fixture, ['permissions' => ['returns:read', 'returns:file'], 'expires' => '30'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(flashed('freshKey'))->toStartWith('ctax_live_');

    $key = app(CustomerApiKeys::class)->forUser($fixture['org']->id, $fixture['ada'])->sole();

    expect($key->client_id)->toBe($fixture['clientId'])
        ->and($key->permissions)->toBe(['returns:read', 'returns:file'])
        ->and($key->expires_at?->isFuture())->toBeTrue();

    $audit = AuditEntry::query()->where('action', 'api_key.created')->sole();

    expect($audit->organization_id)->toBe($fixture['org']->id)
        ->and($audit->actor_type)->toBe(ActorType::User)
        ->and($audit->actor_id)->toBe($fixture['ada'])
        ->and($audit->target_id)->toBe($key->id);

    $row = $this->get(route('account.api-keys'))->assertOk()->inertiaProps('keys.0');

    expect($row['name'])->toBe('Accounting sync')
        ->and($row['appName'])->toBe('Acme Tax')
        ->and($row['holder'])->toBeNull()
        ->and($row['lifecycle']['status'])->toBe('active')
        ->and($row['revokeHref'])->not->toBeNull();
});

it('verifies a key made on the page at the app\'s verify endpoint, capped at what the holder holds', function (): void {
    $fixture = appKeyFixture();
    signInKeyHolder($fixture['ada'], $fixture['org']);

    createAppKey($fixture, ['permissions' => ['returns:read', 'returns:file']])->assertSessionHasNoErrors();
    $plaintext = (string) flashed('freshKey');

    $verify = fn () => $this->withBasicAuth($fixture['clientId'], $fixture['secret'])
        ->postJson('/oauth/api-keys/verify', ['key' => $plaintext])
        ->assertOk()
        ->json();

    expect($verify())->toMatchArray([
        'active' => true,
        'sub' => $fixture['ada'],
        'org' => $fixture['org']->id,
        'permissions' => ['returns:read', 'returns:file'],
    ]);

    // Take the role away: the same key, next request, carries nothing.
    $filer = Role::query()->where('client_id', $fixture['clientId'])->where('key', 'filer')->firstOrFail();
    app(Roles::class)->unassign($fixture['org']->id, $fixture['ada'], $filer->id);

    expect($verify()['permissions'])->toBe([]);
});

it('refuses a permission the person does not hold, and names it', function (): void {
    $fixture = appKeyFixture();
    signInKeyHolder($fixture['ada'], $fixture['org']);

    // A crafted request: the page never offers settings:manage to Ada.
    createAppKey($fixture, ['permissions' => ['returns:read', 'settings:manage']])
        ->assertRedirect(route('account.api-keys'))
        ->assertSessionHasErrors([
            'permissions' => 'You do not hold settings:manage in Acme Tax, so a key cannot carry it. A key can only do what you can do yourself.',
        ]);

    expect(CustomerApiKey::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an app that belongs to another organization, even with a prefix', function (): void {
    $fixture = appKeyFixture();
    $elsewhere = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-app'));
    $foreign = app(ClientRegistry::class)->register(new NewClient('Globex App', organizationId: $elsewhere->id));
    app(CustomerApiKeys::class)->setPrefix($foreign->client->client_id, ApiKeyPrefix::of('globex_live'));

    signInKeyHolder($fixture['ada'], $fixture['org']);

    // An empty permission list, so the framework's own cap has nothing to refuse: the only
    // thing standing between Ada and a key for Globex's app is the organization check.
    createAppKey($fixture, ['client_id' => $foreign->client->client_id, 'permissions' => []])
        ->assertSessionHasErrors(['client_id' => 'That app does not offer API keys to this organization.']);

    expect(CustomerApiKey::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an organization the person is not a member of', function (): void {
    $fixture = appKeyFixture();
    $stranger = app(Organizations::class)->create(new NewOrganization('Globex', 'globex-member'));

    signInKeyHolder($fixture['ada'], $fixture['org']);

    createAppKey($fixture, ['organization_id' => $stranger->id, 'permissions' => []])
        ->assertSessionHasErrors(['organization_id' => 'You are not an active member of that organization.']);

    expect(CustomerApiKey::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('revokes the person\'s own key', function (): void {
    $fixture = appKeyFixture();
    $key = mintAppKey($fixture, $fixture['ada']);
    signInKeyHolder($fixture['ada'], $fixture['org']);

    $this->delete(route('account.api-keys.revoke', ['key' => $key->id, 'organization' => $fixture['org']->id]))
        ->assertSessionHas('status', 'API key revoked.');

    expect(keyIsRevoked($key->id))->toBeTrue();

    $audit = AuditEntry::query()->where('action', 'api_key.revoked')->sole();
    expect($audit->actor_id)->toBe($fixture['ada']);
});

it('never revokes a colleague\'s key from the holder\'s page', function (): void {
    $fixture = appKeyFixture();
    $adasKey = mintAppKey($fixture, $fixture['ada']);
    signInKeyHolder($fixture['bob'], $fixture['org']);

    // Same organization, Ada's real key id: Bob is a member here, and it is still not his.
    $this->delete(route('account.api-keys.revoke', ['key' => $adasKey->id, 'organization' => $fixture['org']->id]))
        ->assertSessionMissing('status');

    expect(keyIsRevoked($adasKey->id))->toBeFalse();
});

it('offers the key page on the rail only where an app offers keys or a key is held', function (): void {
    // Acme's own app, so it is offered to Acme and to nobody else.
    $fixture = appKeyFixture(ownApp: true);
    $plain = app(Organizations::class)->create(new NewOrganization('Plain', 'plain-rail'));
    $carol = app(Subjects::class)->create('carol@plain.test', 'Carol', 'supersecret123');
    app(Memberships::class)->add($plain->id, $carol->id, MembershipRole::Member);

    signInKeyHolder($fixture['ada'], $fixture['org']);
    expect(Console::featureActive('account.api-keys'))->toBeTrue();

    signInKeyHolder($carol->id, $plain);
    expect(Console::featureActive('account.api-keys'))->toBeFalse();
});

it('lights the page being shown in My account, and not Security as well', function (string $route): void {
    $fixture = appKeyFixture();
    signInKeyHolder($fixture['ada'], $fixture['org']);

    $areas = $this->get(route($route))->assertOk()->inertiaProps('shell.areas');
    $account = collect($areas)->firstWhere('key', 'account');

    // `account` is a prefix of both neighbours' route names, and lit on each of them too.
    expect(collect($account['pages'])->where('active', true)->pluck('route')->all())->toBe([$route]);
})->with(['account', 'account.activity', 'account.api-keys']);

// ── The administrators' view ─────────────────────────────────────────────────

it('shows an organization administrator every key in the organization, with its holder', function (): void {
    $fixture = appKeyFixture();
    mintAppKey($fixture, $fixture['ada'], ['returns:read']);
    mintAppKey($fixture, $fixture['bob']);

    $admin = app(Subjects::class)->create('admin@acme-keys.test', 'Admin', 'supersecret123');
    app(Memberships::class)->add($fixture['org']->id, $admin->id, MembershipRole::Admin);
    signInKeyHolder($admin->id, $fixture['org']);

    expect(Console::featureActive('organization.api-keys'))->toBeTrue();

    $page = $this->get(route('directory.api-keys'))->assertOk()->inertiaProps();

    expect($page['help']['topic'])->toBe('member-api-keys')
        ->and(array_column(array_column($page['keys'], 'holder'), 'name'))->toEqualCanonicalizing(['Ada Lovelace', 'Bob'])
        ->and(array_column($page['keys'], 'appName'))->toBe(['Acme Tax', 'Acme Tax']);
});

it('keeps the administrators\' key page from a plain member', function (): void {
    $fixture = appKeyFixture();
    signInKeyHolder($fixture['ada'], $fixture['org']);

    expect(Console::featureActive('organization.api-keys'))->toBeFalse();

    $this->get(route('directory.api-keys'))->assertForbidden();
    $this->delete(route('directory.api-keys.revoke', mintAppKey($fixture, $fixture['bob'])->id))->assertForbidden();
});

it('lets an administrator revoke a member\'s key, recorded as theirs', function (): void {
    $fixture = appKeyFixture();
    $key = mintAppKey($fixture, $fixture['ada']);

    $admin = app(Subjects::class)->create('admin@acme-keys.test', 'Admin', 'supersecret123');
    app(Memberships::class)->add($fixture['org']->id, $admin->id, MembershipRole::Admin);
    signInKeyHolder($admin->id, $fixture['org']);

    $this->delete(route('directory.api-keys.revoke', $key->id))->assertSessionHas('status');

    expect(keyIsRevoked($key->id))->toBeTrue();

    $audit = AuditEntry::query()->where('action', 'api_key.revoked')->sole();
    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->organization_id)->toBe($fixture['org']->id);
});

it('never lets one organization\'s administrator revoke another organization\'s key', function (): void {
    $acme = appKeyFixture();
    $globex = appKeyFixture('globex-keys');
    $globexKey = mintAppKey($globex, $globex['ada']);

    $admin = app(Subjects::class)->create('admin@acme-keys.test', 'Admin', 'supersecret123');
    app(Memberships::class)->add($acme['org']->id, $admin->id, MembershipRole::Owner);
    signInKeyHolder($admin->id, $acme['org']);

    $this->delete(route('directory.api-keys.revoke', $globexKey->id))->assertSessionMissing('status');

    expect(keyIsRevoked($globexKey->id))->toBeFalse();
});

it('binds a key lookup to its organization and holder even with tenant scoping suspended', function (): void {
    $acme = appKeyFixture();
    $globex = appKeyFixture('globex-bound');
    $globexKey = mintAppKey($globex, $globex['ada']);
    $adasKey = mintAppKey($acme, $acme['ada']);

    $bound = app(BoundApiKeys::class);

    // With the tenant scope off, the WHERE clause is the only thing left to refuse these.
    app(TenantContext::class)->withoutScope(function () use ($bound, $acme, $globexKey, $adasKey): void {
        expect($bound->find($acme['org']->id, $globexKey->id))->toBeNull()
            ->and($bound->find($acme['org']->id, $adasKey->id, holderId: $acme['bob']))->toBeNull()
            ->and($bound->find($acme['org']->id, $adasKey->id, holderId: $acme['ada'])?->id)->toBe($adasKey->id);
    });
});

// ── The environment console ──────────────────────────────────────────────────

it('lists and revokes an organization\'s keys on the environment console\'s organization page', function (): void {
    crudSetup();
    $fixture = appKeyFixture('env-keys');
    $key = mintAppKey($fixture, $fixture['ada'], ['returns:read']);

    $keys = $this->get(route('environment.organizations.show', $fixture['org']->id))
        ->assertOk()
        ->inertiaProps('apiKeys');

    expect($keys)->toHaveCount(1)
        ->and($keys[0]['holder']['name'])->toBe('Ada Lovelace')
        ->and($keys[0]['permissions'])->toBe(['returns:read']);

    $this->delete($keys[0]['revokeHref'])->assertSessionHas('status');

    expect(keyIsRevoked($key->id))->toBeTrue();
});

it('revokes nothing on the environment console when the key belongs to a different organization', function (): void {
    crudSetup();
    $acme = appKeyFixture('env-acme');
    $globex = appKeyFixture('env-globex');
    $globexKey = mintAppKey($globex, $globex['ada']);

    $this->delete(route('environment.organizations.api-keys.revoke', [$acme['org']->id, $globexKey->id]))
        ->assertSessionMissing('status');

    expect(keyIsRevoked($globexKey->id))->toBeFalse();
});
