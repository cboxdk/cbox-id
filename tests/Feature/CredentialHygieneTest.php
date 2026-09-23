<?php

declare(strict_types=1);

use App\Platform\Console\ClientLifecycleAudit;
use App\Platform\OrganizationActivity;
use App\Platform\Sudo;
use Carbon\CarbonImmutable;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ClientSecret;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Models\EnvironmentApiKey;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Collection;
use Inertia\Support\SessionKey;

/*
|--------------------------------------------------------------------------
| Credential hygiene
|--------------------------------------------------------------------------
| The two machine-key pages, the app lifecycle and the account's own name: what the list
| says about a key, how long a key lives, and whether minting, revoking, rotating and
| renaming leave a line on the activity log.
*/

beforeEach(function (): void {
    installedDeployment();
});

/**
 * An account owner, signed in with the step-up already given, and the account.
 *
 * @return array{organizationId: string, environmentId: string}
 */
function aKeyManager(): array
{
    ['organization' => $account, 'subjectId' => $ownerId, 'environment' => $environment] = provisionAccount();

    signInAsMember($ownerId);
    app(Sudo::class)->confirm();

    return ['organizationId' => $account->id, 'environmentId' => $environment->id];
}

/**
 * The account's activity-log actions, newest first.
 *
 * @return list<string>
 */
function accountActions(string $organizationId): array
{
    return app(OrganizationActivity::class)->recent($organizationId)
        ->pluck('action')
        ->values()
        ->all();
}

/**
 * The keys the environment-keys page lists, as the page receives them.
 *
 * @return Collection<string, array<string, mixed>>
 */
function listedEnvironmentKeys(string $environmentId): Collection
{
    $keys = test()->get(route('keys', ['environment' => $environmentId]))
        ->assertOk()
        ->inertiaProps('keys');

    return collect(is_array($keys) ? $keys : [])->keyBy('name');
}

/**
 * An environment key by name, read inside ITS environment — the model is hard-scoped, so
 * a bare query from the test's own context finds nothing and every "was not minted"
 * assertion over it would pass for the wrong reason.
 */
function environmentKeyNamed(string $environmentId, string $name): ?EnvironmentApiKey
{
    return app(EnvironmentApiKeys::class)->forEnvironment($environmentId)->firstWhere('name', $name);
}

/**
 * The account's log, narrowed to the actions a test is about — provisioning and signing in
 * write their own entries first.
 *
 * @param  list<string>  $actions
 * @return list<string>
 */
function accountActionsAmong(string $organizationId, array $actions): array
{
    return array_values(array_filter(
        accountActions($organizationId),
        static fn (string $action): bool => in_array($action, $actions, true),
    ));
}

/*
| Environment keys: the list tells a live key from a dead one
*/

it('lists a revoked environment key as revoked, with nothing left to revoke', function (): void {
    ['environmentId' => $environmentId] = aKeyManager();

    $keys = app(EnvironmentApiKeys::class);
    $live = app(PlatformRoot::class)->run(fn () => $keys->issue($environmentId, 'Live', ['users:read']));
    $dead = app(PlatformRoot::class)->run(fn () => $keys->issue($environmentId, 'Dead', ['users:read']));
    $lapsed = app(PlatformRoot::class)->run(fn () => $keys->issue(
        $environmentId,
        'Lapsed',
        ['users:read'],
        CarbonImmutable::now()->subDay(),
    ));
    app(PlatformRoot::class)->run(fn () => $keys->revoke($environmentId, $dead->key->id));

    $listed = listedEnvironmentKeys($environmentId);

    expect($listed['Live']['lifecycle']['status'])->toBe('active')
        ->and($listed['Live']['revokeHref'])->toBeString()
        ->and($listed['Live']['prefix'])->toBe($live->key->prefix)
        // The defect: a revoked key drawn exactly like a live one, Revoke button included.
        ->and($listed['Dead']['lifecycle']['status'])->toBe('revoked')
        ->and($listed['Dead']['revokeHref'])->toBeNull()
        // …and an expired one is not "revoked": nobody pulled it, it ran out.
        ->and($listed['Lapsed']['lifecycle']['status'])->toBe('expired')
        ->and($listed['Lapsed']['revokeHref'])->toBeNull()
        ->and($listed['Lapsed']['lifecycle']['expiresAt'])->toBeString();
});

it('shows each environment scope by its label with its key beside it', function (): void {
    ['environmentId' => $environmentId] = aKeyManager();

    app(PlatformRoot::class)->run(fn () => app(EnvironmentApiKeys::class)
        ->issue($environmentId, 'Reporting', ['organizations:read']));

    expect(listedEnvironmentKeys($environmentId)['Reporting']['scopes'])->toBe([
        ['value' => 'organizations:read', 'label' => 'Read organizations', 'writes' => false],
    ]);
});

it('does not offer the reserved directory scopes no route requires', function (): void {
    ['environmentId' => $environmentId] = aKeyManager();

    $offered = collect((array) $this->get(route('keys'))->assertOk()->inertiaProps('scopes'))
        ->pluck('value')
        ->all();

    expect($offered)->toBe(['organizations:read', 'organizations:write', 'users:read', 'users:write']);

    // And not accepted either: an option the form hides is still POSTable.
    issueEnvironmentKey($environmentId, ['name' => 'Directory sync', 'scopes' => ['directories:write']])
        ->assertSessionHasErrors('scopes.0');

    expect(environmentKeyNamed($environmentId, 'Directory sync'))->toBeNull();
});

/*
| Expiry
*/

it('mints an environment key that expires after the lifetime chosen', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00'));
    ['environmentId' => $environmentId] = aKeyManager();

    issueEnvironmentKey($environmentId, ['name' => 'Quarterly', 'expires' => '90'])
        ->assertSessionHasNoErrors();

    $key = environmentKeyNamed($environmentId, 'Quarterly');

    expect($key->expires_at?->toIso8601String())->toBe('2026-12-23T10:00:00+00:00');
});

it('ends a custom expiry at the end of the day chosen, in UTC', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00'));
    ['environmentId' => $environmentId] = aKeyManager();

    issueEnvironmentKey($environmentId, ['name' => 'Contract', 'expires' => 'custom', 'expiresOn' => '2027-03-01'])
        ->assertSessionHasNoErrors();

    $key = environmentKeyNamed($environmentId, 'Contract');

    // Still working on 1 March — which is what a person who typed "1 March" meant.
    expect($key->expires_at?->toIso8601String())->toBe('2027-03-01T23:59:59+00:00');
});

it('keeps minting a key that never expires when the form sends no lifetime', function (): void {
    ['environmentId' => $environmentId] = aKeyManager();

    // The shape every script written before the field existed posts.
    issueEnvironmentKey($environmentId, ['name' => 'Legacy'])->assertSessionHasNoErrors();

    $key = environmentKeyNamed($environmentId, 'Legacy');

    expect($key)->not->toBeNull()
        ->and($key?->expires_at)->toBeNull();
});

it('refuses a custom expiry that is missing, today, or in the past', function (string $on, string $message): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00'));
    ['environmentId' => $environmentId] = aKeyManager();

    issueEnvironmentKey($environmentId, ['name' => 'Born dead', 'expires' => 'custom', 'expiresOn' => $on])
        ->assertSessionHasErrors(['expiresOn' => $message]);

    expect(environmentKeyNamed($environmentId, 'Born dead'))->toBeNull();
})->with([
    'missing' => ['', 'Choose the date the key should stop working.'],
    'today' => ['2026-09-24', 'Choose a date after today.'],
    'yesterday' => ['2026-09-23', 'Choose a date after today.'],
]);

it('mints an account key with an expiry and lists it as such', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00'));
    ['organizationId' => $organizationId] = aKeyManager();

    $this->from(route('keys.workspace'))
        ->post(route('keys.workspace.store'), ['name' => 'CI', 'role' => 'developer', 'expires' => '30'])
        ->assertSessionHasNoErrors();

    $key = OrganizationApiKey::query()->where('name', 'CI')->firstOrFail();

    expect($key->expires_at?->toIso8601String())->toBe('2026-10-24T10:00:00+00:00');

    $row = collect((array) $this->get(route('keys.workspace'))->assertOk()->inertiaProps('keys'))->firstWhere('name', 'CI');

    expect($row['lifecycle']['status'])->toBe('active')
        ->and($row['lifecycle']['expiresAt'])->toBe('2026-10-24T10:00:00+00:00')
        ->and($row['lifecycle']['createdAt'])->toBeString();

    // Past its date the same key is expired — not "revoked", which is what the page said
    // for every key that was not active. (Its date is moved rather than the clock: a month
    // of travel would expire the session reading the page, too.)
    $key->forceFill(['expires_at' => CarbonImmutable::now()->subMinute()])->save();

    $row = collect((array) $this->get(route('keys.workspace'))->assertOk()->inertiaProps('keys'))->firstWhere('name', 'CI');

    expect($row['lifecycle']['status'])->toBe('expired')
        ->and($row['revokeHref'])->toBeNull()
        ->and($organizationId)->toBeString();
});

/*
| The activity log
*/

it('records minting and revoking an account API key on the account log', function (): void {
    ['organizationId' => $organizationId] = aKeyManager();

    $this->from(route('keys.workspace'))
        ->post(route('keys.workspace.store'), ['name' => 'Automation', 'role' => 'developer'])
        ->assertSessionHasNoErrors();

    $key = OrganizationApiKey::query()->where('name', 'Automation')->firstOrFail();

    $this->from(route('keys.workspace'))->delete(route('keys.workspace.destroy', $key->id))->assertRedirect(route('keys.workspace'));

    $keyActions = ['organization.api_key_created', 'organization.api_key_revoked'];

    expect(accountActionsAmong($organizationId, $keyActions))
        ->toBe(['organization.api_key_revoked', 'organization.api_key_created']);

    $created = app(OrganizationActivity::class)->recent($organizationId)
        ->firstWhere('action', 'organization.api_key_created');

    expect($created?->target_type)->toBe('api_key')
        ->and($created?->target_id)->toBe($key->id)
        ->and($created?->context['role'] ?? null)->toBe('developer');

    // A second revoke of the same key stops nothing and records nothing.
    $this->from(route('keys.workspace'))->delete(route('keys.workspace.destroy', $key->id))->assertRedirect(route('keys.workspace'));

    expect(accountActionsAmong($organizationId, $keyActions))->toHaveCount(2);
})->group('security');

it('records an environment key mint and revoke, naming the key', function (): void {
    ['organizationId' => $organizationId, 'environmentId' => $environmentId] = aKeyManager();

    issueEnvironmentKey($environmentId, ['name' => 'Provisioner'])->assertSessionHasNoErrors();

    $keyId = environmentKeyNamed($environmentId, 'Provisioner')?->id;

    expect($keyId)->toBeString();

    $this->from(route('keys'))
        ->delete(route('keys.destroy', $keyId), ['environment' => $environmentId])
        ->assertRedirect(route('keys'));

    // Twice: the second finds the key already revoked and has nothing to add.
    $this->from(route('keys'))
        ->delete(route('keys.destroy', $keyId), ['environment' => $environmentId])
        ->assertRedirect(route('keys'));

    $entries = app(OrganizationActivity::class)->recent($organizationId)
        ->filter(fn (AuditEntry $entry): bool => str_starts_with($entry->action, 'organization.environment_key_'))
        ->values();

    expect($entries->pluck('action')->all())
        ->toBe(['organization.environment_key_revoked', 'organization.environment_key_created'])
        ->and($entries->last()?->context['key_id'] ?? null)->toBe($keyId);
})->group('security');

it('records renaming the account on the account log, with the name it had', function (): void {
    ['organizationId' => $organizationId] = aKeyManager();

    $this->from(route('organization-settings'))
        ->patch(route('organization-settings.update'), ['name' => 'Acme Holdings'])
        ->assertSessionHasNoErrors();

    // Saving the same name again is not a rename, and the log does not say it was.
    $this->from(route('organization-settings'))
        ->patch(route('organization-settings.update'), ['name' => 'Acme Holdings'])
        ->assertSessionHasNoErrors();

    $entries = app(OrganizationActivity::class)->recent($organizationId)
        ->where('action', 'organization.renamed')
        ->values();

    expect($entries->pluck('action')->all())->toBe(['organization.renamed'])
        ->and($entries->first()?->context)->toMatchArray(['from' => 'Acme', 'to' => 'Acme Holdings']);
})->group('security');

/**
 * Every console-recorded entry for one app, oldest first.
 *
 * @return Collection<int, AuditEntry>
 */
function appLifecycle(string $clientId): Collection
{
    return AuditEntry::query()
        ->where('target_type', 'client')
        ->where('target_id', $clientId)
        ->orderBy('sequence')
        ->get();
}

it('records an app being registered, edited, rotated and deleted on its organization\'s trail', function (): void {
    [$ownerId, $org] = actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();

    registerApp(['name' => 'Billing', 'redirectUris' => 'https://billing.acme.test/callback'])
        ->assertSessionHasNoErrors();

    $client = Client::query()->where('name', 'Billing')->firstOrFail();

    $showing = (array) $this->get(route('clients.show', $client->id))->assertOk()->inertiaProps('client');
    $form = [
        'name' => 'Billing (EU)',
        'redirectUris' => $showing['redirectUris'],
        'postLogoutRedirectUris' => $showing['postLogoutRedirectUris'],
        'scopes' => $showing['scopes'],
        'customScopes' => $showing['customScopes'],
    ];

    $this->from(route('clients.show', $client->id))->patch(route('clients.update', $client->id), $form)
        ->assertSessionHasNoErrors();
    // Saved again unchanged: not an edit.
    $this->from(route('clients.show', $client->id))->patch(route('clients.update', $client->id), $form)
        ->assertSessionHasNoErrors();

    $this->from(route('clients.show', $client->id))->post(route('clients.rotate', $client->id))
        ->assertSessionHasNoErrors();
    $this->delete(route('clients.destroy', $client->id))->assertRedirect();

    $entries = appLifecycle($client->client_id);

    expect($entries->pluck('action')->all())->toBe([
        ClientLifecycleAudit::CREATED,
        ClientLifecycleAudit::UPDATED,
        ClientLifecycleAudit::SECRET_ROTATED,
        ClientLifecycleAudit::DELETED,
    ]);

    foreach ($entries as $entry) {
        // On the owning organization's chain, which is what the activity page filters by,
        // attributed to the tenant administrator who acted, and marked as the console's so
        // the framework's own entries can be told apart once it writes them.
        expect($entry->organization_id)->toBe($org->id)
            ->and($entry->actor_type)->toBe(ActorType::User)
            ->and($entry->actor_id)->toBe($ownerId)
            ->and($entry->context['recorded_by'] ?? null)->toBe('console');
    }

    expect($entries[1]->context['changed'] ?? null)->toBe(['name']);
})->group('security');

it('records an environment administrator acting on an environment-owned app on the system trail', function (): void {
    crudSetup();
    confirmConsoleStepUp();

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Back office',
        type: ClientType::Confidential,
        redirectUris: ['https://a.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ))->client;

    $this->from(route('environment.clients.show', $client->id))
        ->post(route('environment.clients.rotate', $client->id))
        ->assertSessionHasNoErrors();

    $entry = appLifecycle($client->client_id)->sole();

    expect($entry->action)->toBe(ClientLifecycleAudit::SECRET_ROTATED)
        ->and($entry->organization_id)->toBeNull()
        ->and($entry->actor_type)->toBe(ActorType::OrganizationMember);
})->group('security');

it('rotates a secret through the framework\'s own definition of one', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();

    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Portal',
        type: ClientType::Confidential,
        redirectUris: ['https://portal.acme.test/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $org->id,
    ))->client;

    $this->from(route('clients.show', $client->id))->post(route('clients.rotate', $client->id))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('revealedSecret');

    $flash = session()->get(SessionKey::FLASH_DATA, []);
    $secret = is_array($flash) ? ($flash['revealedSecret'] ?? null) : null;

    expect($secret)->toBeString()->toStartWith('csec_')
        ->and($client->fresh()?->secret_hash)->toBe(ClientSecret::hash((string) $secret))
        // The registry verifies with the same definition, which is the point of using it.
        ->and(app(ClientRegistry::class)->verifySecret($client->fresh() ?? $client, (string) $secret))->toBeTrue();
});

/*
| Roles
*/

it('names the owning organization of each role when every organization is in view', function (): void {
    crudSetup();

    $acme = app(Organizations::class)->create(new NewOrganization('Acme Retail', 'acme-retail'));
    $globex = app(Organizations::class)->create(new NewOrganization('Globex', 'globex'));
    app(Roles::class)->define($acme->id, 'Editor');
    app(Roles::class)->define($globex->id, 'Editor');
    app(Roles::class)->define(null, 'Support');

    $rows = collect((array) $this->get(route('environment.roles'))->assertOk()->inertiaProps('roles'));

    // Two tenants' "Editor" were two identical rows.
    expect($rows->where('name', 'Editor')->pluck('organization')->sort()->values()->all())
        ->toBe(['Acme Retail', 'Globex'])
        // An environment-wide role belongs to no organization, and says so another way.
        ->and($rows->firstWhere('name', 'Support')['organization'])->toBeNull();
});

it('does not repeat the organization on a list that is already one organization\'s', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    app(Roles::class)->define($org->id, 'Editor');

    $rows = collect((array) $this->get(route('roles'))->assertOk()->inertiaProps('roles'));

    expect($rows->firstWhere('name', 'Editor')['organization'])->toBeNull();
});
