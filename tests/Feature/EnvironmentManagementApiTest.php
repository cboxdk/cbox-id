<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\UserStatus;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\AccountMembers;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\AccountRole;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

// Every HTTP request resolves to the `env_test` environment (TestCase seeds it as
// the default host fallback), so keys issued into env_test authenticate.
beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * @param  list<EnvironmentApiScope>  $scopes
 */
function envKey(array $scopes, string $environmentId = 'env_test'): string
{
    return app(EnvironmentApiKeys::class)->issue(
        $environmentId,
        'test key',
        array_map(fn (EnvironmentApiScope $s): string => $s->value, $scopes),
    )->plaintext;
}

it('serves the environment-plane OpenAPI spec', function (): void {
    $this->get('/api/v1/environment/openapi.yaml')
        ->assertOk()
        ->assertHeader('content-type', 'application/yaml')
        ->assertSee('Environment Management API');
});

it('rejects a request with no key or a bogus key', function (): void {
    $this->getJson('/api/v1/organizations')->assertUnauthorized();
    $this->withToken('cbid_env_bogus')->getJson('/api/v1/organizations')->assertUnauthorized();
    // An account-plane key is not accepted on the environment plane.
    $this->withToken('cbid_acc_wrongplane')->getJson('/api/v1/organizations')->assertUnauthorized();
});

it('does not accept a key minted for a different environment', function (): void {
    // A perfectly valid key — but for env_other, while this host resolves env_test.
    $foreign = envKey([EnvironmentApiScope::OrganizationsRead], 'env_other');

    $this->withToken($foreign)->getJson('/api/v1/organizations')->assertUnauthorized();
});

it('lists and creates organizations, gating writes behind the write scope', function (): void {
    $read = envKey([EnvironmentApiScope::OrganizationsRead]);
    $write = envKey([EnvironmentApiScope::OrganizationsRead, EnvironmentApiScope::OrganizationsWrite]);

    // A read key can list…
    $this->withToken($read)->getJson('/api/v1/organizations')
        ->assertOk()->assertJsonPath('meta.has_more', false)->assertJsonCount(0, 'data');

    // …but cannot create.
    $this->withToken($read)->postJson('/api/v1/organizations', ['name' => 'Acme', 'slug' => 'acme'])
        ->assertForbidden()->assertJsonPath('error', 'forbidden');

    // A write key creates, and the row is readable back.
    $created = $this->withToken($write)->postJson('/api/v1/organizations', ['name' => 'Acme', 'slug' => 'acme'])
        ->assertCreated()->assertJsonPath('data.slug', 'acme')->json('data.id');

    $this->withToken($read)->getJson("/api/v1/organizations/{$created}")
        ->assertOk()->assertJsonPath('data.name', 'Acme');

    // Duplicate slug is refused.
    $this->withToken($write)->postJson('/api/v1/organizations', ['name' => 'Dup', 'slug' => 'acme'])
        ->assertStatus(422)->assertJsonPath('error', 'slug_taken');
});

it('creates, reads, and deactivates users', function (): void {
    $key = envKey([EnvironmentApiScope::UsersRead, EnvironmentApiScope::UsersWrite]);

    $id = $this->withToken($key)->postJson('/api/v1/users', ['email' => 'ada@acme.com', 'name' => 'Ada'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'ada@acme.com')
        ->assertJsonPath('data.status', UserStatus::Active->value)
        ->json('data.id');

    // Duplicate email refused.
    $this->withToken($key)->postJson('/api/v1/users', ['email' => 'ada@acme.com'])
        ->assertStatus(422)->assertJsonPath('error', 'email_taken');

    // Deactivate is a soft disable, not a delete.
    $this->withToken($key)->deleteJson("/api/v1/users/{$id}")
        ->assertOk()->assertJsonPath('data.status', UserStatus::Disabled->value);

    expect(app(Subjects::class)->isActive($id))->toBeFalse();
});

it('gates user reads behind the read scope', function (): void {
    app(Subjects::class)->create('someone@acme.com', 'Someone');
    $writeOnly = envKey([EnvironmentApiScope::UsersWrite]);

    // A key without users:read cannot enumerate users (PII).
    $this->withToken($writeOnly)->getJson('/api/v1/users')->assertForbidden();
});

it('paginates by cursor over the ULID id', function (): void {
    $orgs = app(Organizations::class);
    foreach (range(1, 3) as $n) {
        $orgs->create(new NewOrganization("Org {$n}", "org-{$n}"));
    }

    $read = envKey([EnvironmentApiScope::OrganizationsRead]);

    $first = $this->withToken($read)->getJson('/api/v1/organizations?limit=2')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.has_more', true);

    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    $this->withToken($read)->getJson("/api/v1/organizations?limit=2&after={$cursor}")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false);
});

// ── Console: minting environment keys ───────────────────────────────────────

it('lets an environment manager mint a scoped key for their environment in the console', function (): void {
    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    session()->put(AccountAuth::SESSION_KEY, $result->member->id);

    $component = Volt::test('workspace.environment-api-keys')
        ->set('newKeyName', 'Provisioner')
        ->set('newKeyScopes', [EnvironmentApiScope::UsersWrite->value])
        ->call('createKey')
        ->assertHasNoErrors();

    expect($component->get('freshKey'))->toStartWith('cbid_env_');

    $keys = app(EnvironmentApiKeys::class)->forEnvironment($result->environment->id);
    expect($keys)->toHaveCount(1)
        ->and($keys->first()?->scopes)->toBe([EnvironmentApiScope::UsersWrite->value]);
});

it('redirects a non-manager away from the environment-keys console', function (): void {
    $result = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner2@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    $members = app(AccountMembers::class);
    // Viewer is read-only — it cannot manage environments, so it can't mint env keys.
    $viewer = $members->invite($result->account->id, 'viewer@acme.example', AccountRole::Viewer);
    $members->activate($viewer->id, 'a-strong-unbreached-passphrase');

    $this->withSession([AccountAuth::SESSION_KEY => $viewer->id])
        ->get(route('workspace.environment-keys'))
        ->assertRedirect(route('workspace.home'));
});
