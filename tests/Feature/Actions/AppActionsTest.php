<?php

declare(strict_types=1);

use App\Platform\Actions\Idempotency\IdempotencyRecord;
use Cbox\Id\AccessControl\Contracts\ManifestFetcher;
use Cbox\Id\AccessControl\Manifest\DeclaredRole;
use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\Models\EnvironmentApiKey;

/*
|--------------------------------------------------------------------------
| Apps, as actions: every console write is now a management API endpoint too.
|--------------------------------------------------------------------------
|
| The console's pages and the management API run the same actions, so what is proved here
| is the API's half: each endpoint works, needs its scope, cannot reach another
| environment's app, refuses with a stable code, shows a minted secret once and never keeps
| it for a replay, and records what it did as the KEY's act.
*/

/**
 * A key in env_test holding these scopes, and its row.
 *
 * @param  list<EnvironmentApiScope>  $scopes
 * @return array{0: string, 1: EnvironmentApiKey}
 */
function appActionsKey(array $scopes = [EnvironmentApiScope::AppsRead, EnvironmentApiScope::AppsWrite]): array
{
    $issued = app(EnvironmentApiKeys::class)->issue(
        'env_test',
        'Apps worker',
        array_map(fn (EnvironmentApiScope $scope): string => $scope->value, $scopes),
    );

    return [$issued->plaintext, $issued->key];
}

/** A confidential web app, registered over the API. */
function appActionsWebApp(string $key, array $changes = []): array
{
    return test()->withToken($key)->postJson('/api/v1/apps', [
        'name' => 'Tax web',
        'type' => 'web',
        'redirect_uris' => ['https://tax.example/callback'],
        ...$changes,
    ])->assertCreated()->json('data');
}

function appActionsAudit(string $action): ?AuditEntry
{
    return AuditEntry::query()->where('action', $action)->orderByDesc('id')->first();
}

it('registers, reads, edits and deletes an app, every write on the trail as the key', function (): void {
    [$key, $row] = appActionsKey();

    $app = appActionsWebApp($key);

    expect($app['type'])->toBe('web')
        ->and($app['client_secret'])->toStartWith('csec_')
        ->and(appActionsAudit('app.created')?->actor_type)->toBe(ActorType::Service)
        ->and(appActionsAudit('app.created')?->actor_id)->toBe($row->id);

    // By id and by client_id; never a secret once created.
    $this->withToken($key)->getJson("/api/v1/apps/{$app['id']}")->assertOk()->assertJsonMissingPath('data.client_secret');
    $this->withToken($key)->getJson("/api/v1/apps/{$app['client_id']}")->assertOk()->assertJsonPath('data.id', $app['id']);

    $this->withToken($key)->patchJson("/api/v1/apps/{$app['id']}", [
        'name' => 'Tax web (EU)',
        'redirect_uris' => ['https://eu.tax.example/callback'],
    ])->assertOk()
        ->assertJsonPath('data.name', 'Tax web (EU)')
        ->assertJsonPath('data.redirect_uris', ['https://eu.tax.example/callback'])
        ->assertJsonPath('data.post_logout_redirect_uris', []);

    // What it did not send is unchanged.
    expect(Client::query()->findOrFail($app['id'])->scopes)->toEqualCanonicalizing($app['scopes']);

    expect(appActionsAudit('app.updated')?->actor_id)->toBe($row->id);

    $this->withToken($key)->deleteJson("/api/v1/apps/{$app['id']}")->assertNoContent();

    expect(Client::query()->whereKey($app['id'])->exists())->toBeFalse()
        ->and(appActionsAudit('app.deleted')?->actor_id)->toBe($row->id);
});

it('reads a short-form app back as the kind it was registered as', function (): void {
    [$key] = appActionsKey();

    // The kind is matched against the grants in the order the preset states them, so a
    // registration that sorted them read a CLI back as "advanced".
    expect(appActionsWebApp($key, ['name' => 'Acme CLI', 'type' => 'cli', 'redirect_uris' => []])['type'])->toBe('cli');
});

it('refuses a short form without a name, and an advanced one without its type and grants', function (): void {
    [$key] = appActionsKey();

    $this->withToken($key)->postJson('/api/v1/apps', ['type' => 'web'])
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    $this->withToken($key)->postJson('/api/v1/apps', ['name' => 'Hybrid', 'type' => 'advanced'])
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    $this->withToken($key)->postJson('/api/v1/apps', ['name' => 'Nowhere', 'organization_id' => 'org_missing'])
        ->assertUnprocessable()->assertJsonPath('error', 'organization_not_found');
});

it('needs apps:write for every write and apps:read for every read', function (): void {
    [$writer] = appActionsKey();
    [$reader] = appActionsKey([EnvironmentApiScope::AppsRead]);
    [$none] = appActionsKey([EnvironmentApiScope::ApisRead]);
    $id = appActionsWebApp($writer)['id'];

    $writes = [
        ['postJson', '/api/v1/apps'],
        ['patchJson', "/api/v1/apps/{$id}"],
        ['deleteJson', "/api/v1/apps/{$id}"],
        ['putJson', "/api/v1/apps/{$id}/manifest"],
        ['postJson', "/api/v1/apps/{$id}/manifest/sync"],
        ['putJson', "/api/v1/apps/{$id}/scopes"],
        ['postJson', "/api/v1/apps/{$id}/secrets"],
        ['deleteJson', "/api/v1/apps/{$id}/secrets/s1"],
        ['putJson', "/api/v1/apps/{$id}/settings/token-lifetime"],
        ['putJson', "/api/v1/apps/{$id}/settings/token-exchange"],
        ['putJson', "/api/v1/apps/{$id}/settings/backchannel-logout"],
        ['putJson', "/api/v1/apps/{$id}/settings/api-key-prefix"],
        ['postJson', "/api/v1/apps/{$id}/copy"],
    ];

    foreach ($writes as [$method, $uri]) {
        $this->withToken($reader)->{$method}($uri, [])->assertForbidden()->assertJsonPath('error', 'forbidden');
    }

    foreach (['/api/v1/apps', "/api/v1/apps/{$id}", "/api/v1/apps/{$id}/blueprint", "/api/v1/apps/{$id}/secrets"] as $uri) {
        $this->withToken($none)->getJson($uri)->assertForbidden();
        $this->withToken($reader)->getJson($uri)->assertOk();
    }
})->group('security');

it('answers 404 for an app of another environment, on every endpoint', function (): void {
    [$key] = appActionsKey();

    $foreign = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), fn (): Client => app(ClientRegistry::class)->register(new NewClient(
        name: 'Elsewhere',
        type: ClientType::Confidential,
        redirectUris: ['https://elsewhere.example/callback'],
        grantTypes: ['authorization_code', 'refresh_token'],
    ))->client);

    foreach ([$foreign->id, $foreign->client_id] as $id) {
        $this->withToken($key)->getJson("/api/v1/apps/{$id}")->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->withToken($key)->getJson("/api/v1/apps/{$id}/blueprint")->assertNotFound();
        $this->withToken($key)->getJson("/api/v1/apps/{$id}/secrets")->assertNotFound();
        $this->withToken($key)->patchJson("/api/v1/apps/{$id}", ['name' => 'Mine now'])->assertNotFound();
        $this->withToken($key)->putJson("/api/v1/apps/{$id}/scopes", ['scopes' => []])->assertNotFound();
        $this->withToken($key)->postJson("/api/v1/apps/{$id}/secrets", ['grace_seconds' => 0])->assertNotFound();
        $this->withToken($key)->putJson("/api/v1/apps/{$id}/settings/token-lifetime", ['access_token_ttl' => 600])->assertNotFound();
        $this->withToken($key)->deleteJson("/api/v1/apps/{$id}")->assertNotFound();
    }

    expect(app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), fn (): ?Client => Client::query()->find($foreign->id))?->name)->toBe('Elsewhere');
})->group('security');

it('rotates a secret with an overlap, shows it once, and records it as the key', function (): void {
    [$key, $row] = appActionsKey();
    $app = appActionsWebApp($key);
    $client = Client::query()->findOrFail($app['id']);

    $rotated = $this->withToken($key)->postJson("/api/v1/apps/{$app['id']}/secrets", ['grace_seconds' => 3600])
        ->assertCreated()->json('data');

    expect($rotated['client_secret'])->toStartWith('csec_')->not->toBe($app['client_secret'])
        ->and($rotated['previous_expire_at'])->not->toBeNull()
        ->and(app(ClientRegistry::class)->verifySecret($client, $app['client_secret']))->toBeTrue()
        ->and(app(ClientRegistry::class)->verifySecret($client, $rotated['client_secret']))->toBeTrue()
        ->and(appActionsAudit('app.secret_rotated')?->actor_id)->toBe($row->id);

    $listed = $this->withToken($key)->getJson("/api/v1/apps/{$app['id']}/secrets")->assertOk()->json('data');

    expect($listed)->toHaveCount(2)
        ->and(json_encode($listed))->not->toContain($rotated['client_secret'])
        ->and(json_encode($listed))->not->toContain($app['client_secret']);

    $this->travel(2)->hours();

    expect(app(ClientRegistry::class)->verifySecret($client, $app['client_secret']))->toBeFalse();
});

it('needs the grace period said out loud, and refuses a rotation for an app with no secret', function (): void {
    [$key] = appActionsKey();
    $web = appActionsWebApp($key);
    $spa = appActionsWebApp($key, ['name' => 'Tax SPA', 'type' => 'spa']);

    $this->withToken($key)->postJson("/api/v1/apps/{$web['id']}/secrets", [])
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    $this->withToken($key)->postJson("/api/v1/apps/{$spa['id']}/secrets", ['grace_seconds' => 0])
        ->assertUnprocessable()->assertJsonPath('error', 'public_client');
});

it('revokes one secret, never the last live one, and says when one already stopped', function (): void {
    [$key, $row] = appActionsKey();
    $app = appActionsWebApp($key);

    $only = $this->withToken($key)->getJson("/api/v1/apps/{$app['id']}/secrets")->json('data.0.id');

    $this->withToken($key)->deleteJson("/api/v1/apps/{$app['id']}/secrets/{$only}")
        ->assertUnprocessable()->assertJsonPath('error', 'last_live_secret');

    $this->withToken($key)->postJson("/api/v1/apps/{$app['id']}/secrets", ['grace_seconds' => 3600])->assertCreated();

    $this->withToken($key)->deleteJson("/api/v1/apps/{$app['id']}/secrets/{$only}")->assertNoContent();

    expect(appActionsAudit('app.secret_revoked')?->actor_id)->toBe($row->id);

    // Revoked is gone: a second revocation finds nothing, the 404 any unknown id gets.
    $this->withToken($key)->deleteJson("/api/v1/apps/{$app['id']}/secrets/{$only}")
        ->assertNotFound();
});

/*
 * A minted secret is shown to the first answer and to nobody after it: an idempotent
 * replay returns the same app with `client_secret: null`, and the stored record never held
 * the plaintext at all.
 */
it('never keeps a minted client secret for an idempotent replay', function (): void {
    [$key] = appActionsKey();
    $body = ['name' => 'Retry', 'type' => 'service'];

    $first = $this->withToken($key)->withHeader('Idempotency-Key', 'app-1')->postJson('/api/v1/apps', $body)->assertCreated();
    $again = $this->withToken($key)->withHeader('Idempotency-Key', 'app-1')->postJson('/api/v1/apps', $body)->assertCreated();

    $secret = (string) $first->json('data.client_secret');

    expect($secret)->toStartWith('csec_')
        ->and($again->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($again->json('data.id'))->toBe($first->json('data.id'))
        ->and($again->json('data.client_secret'))->toBeNull()
        ->and(Client::query()->where('name', 'Retry')->count())->toBe(1);

    $rotated = $this->withToken($key)->withHeader('Idempotency-Key', 'rot-1')->postJson("/api/v1/apps/{$first->json('data.id')}/secrets", ['grace_seconds' => 0])->assertCreated();
    $replayed = $this->withToken($key)->withHeader('Idempotency-Key', 'rot-1')->postJson("/api/v1/apps/{$first->json('data.id')}/secrets", ['grace_seconds' => 0])->assertCreated();

    expect($replayed->json('data.id'))->toBe($rotated->json('data.id'))
        ->and($replayed->json('data.client_secret'))->toBeNull();

    $stored = json_encode(IdempotencyRecord::query()->pluck('payload')->all());

    expect($stored)->not->toContain($secret)
        ->and($stored)->not->toContain((string) $rotated->json('data.client_secret'));
})->group('security');

it('replaces the scope set, and refuses a registered API\'s scope the app\'s owner may not hold', function (): void {
    [$key] = appActionsKey([EnvironmentApiScope::AppsRead, EnvironmentApiScope::AppsWrite, EnvironmentApiScope::ApisWrite]);
    $organization = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));

    $this->withToken($key)->postJson('/api/v1/apis', [
        'identifier' => 'https://api.ledger.example',
        'name' => 'Ledger',
        'scopes' => [['key' => 'ledger:close', 'tenant_requestable' => false]],
    ])->assertCreated();

    $app = appActionsWebApp($key, ['organization_id' => $organization->id]);

    $this->withToken($key)->putJson("/api/v1/apps/{$app['id']}/scopes", ['scopes' => ['openid', 'email']])->assertOk();

    expect(Client::query()->findOrFail($app['id'])->scopes)->toEqualCanonicalizing(['openid', 'email']);

    $refused = $this->withToken($key)->putJson("/api/v1/apps/{$app['id']}/scopes", ['scopes' => ['openid', 'ledger:close']])
        ->assertUnprocessable()->assertJsonPath('error', 'scope_not_grantable');

    expect($refused->json('message'))->toContain('ledger:close')
        ->and(Client::query()->findOrFail($app['id'])->scopes)->toEqualCanonicalizing(['openid', 'email']);

    $this->withToken($key)->putJson("/api/v1/apps/{$app['id']}/scopes", [])
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    // An empty set is a set: the one that removes every scope.
    $this->withToken($key)->putJson("/api/v1/apps/{$app['id']}/scopes", ['scopes' => []])
        ->assertOk()->assertJsonPath('data.scopes', []);
});

/*
 * The platform scopes only the console grants are refused to a key on the scope endpoint
 * too — not only at registration, or a key could register a plain app and then edit the
 * vault onto it. One an administrator already granted survives an edit that keeps it.
 */
it('refuses a key giving an app a scope reserved for the console, and keeps one already granted', function (string $scope): void {
    [$key] = appActionsKey();
    $app = appActionsWebApp($key);

    $this->withToken($key)->putJson("/api/v1/apps/{$app['id']}/scopes", ['scopes' => ['openid', $scope]])
        ->assertUnprocessable()->assertJsonPath('error', 'scope_not_grantable');

    expect(Client::query()->findOrFail($app['id'])->scopes)->not->toContain($scope);

    // Granted in the console…
    $granted = app(ClientRegistry::class)->register(new NewClient(
        name: 'Vault reader',
        type: ClientType::Confidential,
        grantTypes: ['client_credentials'],
        scopes: [$scope],
    ))->client;

    // …and kept by a key's edit that leaves it in place.
    $this->withToken($key)->putJson("/api/v1/apps/{$granted->id}/scopes", ['scopes' => [$scope, 'openid']])->assertOk();

    expect($granted->fresh()?->scopes)->toEqualCanonicalizing([$scope, 'openid']);
})->with(['vault.manage', 'decisions:read'])->group('security');

it('changes each setting on its own, and refuses what the registry would', function (): void {
    [$key, $row] = appActionsKey();
    $app = appActionsWebApp($key);
    $spa = appActionsWebApp($key, ['name' => 'Tax SPA', 'type' => 'spa']);
    $base = "/api/v1/apps/{$app['id']}/settings";

    $this->withToken($key)->putJson("{$base}/token-lifetime", ['access_token_ttl' => 900])
        ->assertOk()->assertJsonPath('data.access_token_ttl', 900);
    $this->withToken($key)->putJson("{$base}/token-lifetime", ['access_token_ttl' => 5])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_client_metadata');
    $this->withToken($key)->putJson("{$base}/token-lifetime", ['access_token_ttl' => null])
        ->assertOk()->assertJsonPath('data.access_token_ttl', null);

    $this->withToken($key)->putJson("{$base}/token-exchange", ['enabled' => true])
        ->assertOk()->assertJsonFragment(['urn:ietf:params:oauth:grant-type:token-exchange']);
    $this->withToken($key)->putJson("/api/v1/apps/{$spa['id']}/settings/token-exchange", ['enabled' => true])
        ->assertUnprocessable()->assertJsonPath('error', 'public_client');

    $this->withToken($key)->putJson("{$base}/backchannel-logout", ['uri' => 'http://tax.example/logout'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_client_metadata');
    $this->withToken($key)->putJson("{$base}/backchannel-logout", ['uri' => 'https://tax.example/logout', 'session_required' => true])
        ->assertOk()
        ->assertJsonPath('data.backchannel_logout_uri', 'https://tax.example/logout')
        ->assertJsonPath('data.backchannel_logout_session_required', true);

    $this->withToken($key)->putJson("{$base}/api-key-prefix", ['prefix' => 'tax_live'])
        ->assertOk()->assertJsonPath('data.api_key_prefix', 'tax_live');
    $this->withToken($key)->putJson("/api/v1/apps/{$spa['id']}/settings/api-key-prefix", ['prefix' => 'tax_live'])
        ->assertUnprocessable()->assertJsonPath('error', 'api_key_prefix_taken');
    $this->withToken($key)->putJson("{$base}/api-key-prefix", ['prefix' => 'NOT A PREFIX'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_client_metadata');

    // Each change is the key's, and one entry naming what changed.
    $updates = AuditEntry::query()->where('action', 'app.updated')->where('actor_id', $row->id)->count();

    expect($updates)->toBe(5);
});

it('saves a manifest URL, syncs it, and refuses a sync with nothing to fetch', function (): void {
    [$key] = appActionsKey();
    $app = appActionsWebApp($key);

    $this->withToken($key)->postJson("/api/v1/apps/{$app['id']}/manifest/sync")
        ->assertUnprocessable()->assertJsonPath('error', 'no_manifest_url');

    $this->withToken($key)->putJson("/api/v1/apps/{$app['id']}/manifest", ['manifest_url' => 'not a url'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_client_metadata');

    $this->withToken($key)->putJson("/api/v1/apps/{$app['id']}/manifest", ['manifest_url' => 'https://tax.example/.well-known/cbox-authz'])
        ->assertOk()->assertJsonPath('data.manifest_url', 'https://tax.example/.well-known/cbox-authz');

    // A stand-in fetcher, so this proves the wiring rather than the network.
    app()->instance(ManifestFetcher::class, new class implements ManifestFetcher
    {
        public function fetch(string $url): Manifest
        {
            return new Manifest('1', [], [new DeclaredRole('support', 'Support', null, [])]);
        }
    });

    $this->withToken($key)->postJson("/api/v1/apps/{$app['id']}/manifest/sync")
        ->assertOk()->assertJsonPath('data.roles_declared', 1);

    expect(Role::query()->where('client_id', $app['client_id'])->where('key', 'support')->exists())->toBeTrue();

    app()->instance(ManifestFetcher::class, new class implements ManifestFetcher
    {
        public function fetch(string $url): Manifest
        {
            throw new RuntimeException('The manifest host refused the connection.');
        }
    });

    $this->withToken($key)->postJson("/api/v1/apps/{$app['id']}/manifest/sync")
        ->assertUnprocessable()
        ->assertJsonPath('error', 'manifest_sync_failed')
        ->assertJsonPath('message', 'The manifest host refused the connection.');

    $this->withToken($key)->putJson("/api/v1/apps/{$app['id']}/manifest", ['manifest_url' => null])
        ->assertOk()->assertJsonPath('data.manifest_url', null);
});

it('refuses a key copying an app into another environment, and points at the blueprint', function (): void {
    [$key] = appActionsKey();
    $app = appActionsWebApp($key);

    $this->withToken($key)->postJson("/api/v1/apps/{$app['id']}/copy", ['environment_id' => 'env_other', 'name' => 'Tax web'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'environment_not_reachable');

    expect(app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_other'), fn (): int => Client::query()->count()))->toBe(0);
})->group('security');
