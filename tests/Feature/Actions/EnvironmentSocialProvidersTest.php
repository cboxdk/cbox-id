<?php

declare(strict_types=1);

use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\SignInProviders;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Social login for the whole environment, inherited by every organization.
|--------------------------------------------------------------------------
|
| "Turn on Google for my app" is one set of credentials offered on every sign-in page in
| the environment. An organization may bring its own (which then stands in for the
| environment's on its page) or turn the environment's off for its page. Every step is an
| action, so the management API and the console are the same write.
*/

/** A tenant environment served on the test host, and a key for it with $scopes. */
function espTenant(array $scopes = ['signin:read', 'signin:write']): array
{
    multiTenantDeployment();
    $tenant = provisionAccount();

    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));

    $issued = app(EnvironmentApiKeys::class)->issue($tenant['environment']->id, 'Social worker', $scopes);

    return ['environment' => $tenant['environment']->refresh(), 'key' => $issued->plaintext, 'keyId' => (string) $issued->key->id];
}

function espOrg(string $name = 'Acme'): Organization
{
    return app(Organizations::class)->create(new NewOrganization($name, Str::slug($name).'-'.Str::lower(Str::random(4))));
}

function espAudit(string $action): ?AuditEntry
{
    return AuditEntry::query()->where('action', $action)->orderByDesc('sequence')->first();
}

it('turns GitHub on for the whole environment, under a reserved id, and every organization offers it', function (): void {
    ['key' => $key, 'keyId' => $keyId] = espTenant();
    $acme = espOrg();
    $reserved = strtolower((string) Str::ulid());

    $created = $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', [
        'environment_wide' => true,
        'provider' => 'github',
        'client_id' => 'gh-client',
        'client_secret' => 'gh-very-secret',
        'scopes' => ['read:org'],
        'reserved_id' => $reserved,
    ])->assertCreated()
        ->assertJsonPath('data.id', $reserved)
        ->assertJsonPath('data.organization_id', null)
        ->assertJsonPath('data.level', 'environment')
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.scopes', ['read:org']);

    // The redirect URI shown before saving is the one it has after.
    expect($created->json('data.callback_uri'))->toEndWith('/sso/oauth2/'.$reserved.'/callback')
        ->and($created->getContent())->not->toContain('gh-very-secret')
        // Announced by the framework's activation, attributed to the key.
        ->and(espAudit('connection.activated')?->actor_id)->toBe($keyId);

    $this->withToken($key)->getJson('/api/v1/sign-in/social-providers/offered?organization_id='.$acme->id)
        ->assertOk()
        ->assertJsonPath('data.providers.0.id', $reserved)
        ->assertJsonPath('data.providers.0.source', 'environment')
        ->assertJsonPath('data.not_inherited', []);

    $this->withToken($key)->getJson('/api/v1/sign-in/social-providers/offered')
        ->assertOk()
        ->assertJsonPath('data.organization_id', null)
        ->assertJsonPath('data.providers.0.provider', 'github');

    $this->withToken($key)->getJson('/api/v1/sign-in/social-providers?level=environment')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('makes the owner a decision: neither an organization nor environment_wide is refused', function (): void {
    ['key' => $key] = espTenant();
    $acme = espOrg();
    $enable = fn (array $body) => $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', [
        'provider' => 'github', 'client_id' => 'c', 'client_secret' => 's', ...$body,
    ]);

    $enable([])->assertUnprocessable()->assertJsonPath('error', 'owner_required');
    $enable(['environment_wide' => true, 'organization_id' => $acme->id])->assertUnprocessable()->assertJsonPath('error', 'ambiguous_owner');
    $enable(['environment_wide' => true, 'reserved_id' => 'not-a-ulid'])->assertUnprocessable()->assertJsonPath('error', 'invalid_provider');
    $enable(['environment_wide' => true, 'scopes' => ['read org']])->assertUnprocessable()->assertJsonPath('error', 'invalid_scope');

    $enable(['environment_wide' => true])->assertCreated();
    $enable(['environment_wide' => true])->assertUnprocessable()->assertJsonPath('error', 'already_enabled');
    // The organization may still have its own — that is the override.
    $enable(['organization_id' => $acme->id])->assertCreated();
});

it('lets an organization\'s own provider replace the environment\'s, and turn the environment\'s off', function (): void {
    ['key' => $key] = espTenant(['signin:read', 'signin:write']);
    $acme = espOrg('Acme');
    $bank = espOrg('Bank');
    $other = espOrg('Other');

    $environmentGitHub = $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', ['environment_wide' => true, 'provider' => 'github', 'client_id' => 'env', 'client_secret' => 's'])->json('data.id');
    $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', ['environment_wide' => true, 'provider' => 'discord', 'client_id' => 'env', 'client_secret' => 's'])->assertCreated();
    $acmeGitHub = $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', ['organization_id' => $acme->id, 'provider' => 'github', 'client_id' => 'acme', 'client_secret' => 's'])->json('data.id');

    // Acme's own GitHub in the environment's place.
    $offered = fn (Organization $organization): array => collect($this->withToken($key)->getJson('/api/v1/sign-in/social-providers/offered?organization_id='.$organization->id)->json('data.providers'))
        ->mapWithKeys(fn (array $row): array => [$row['provider'] => [$row['id'], $row['source']]])->all();

    expect($offered($acme))->toBe(['discord' => [$offered($other)['discord'][0], 'environment'], 'github' => [$acmeGitHub, 'organization']])
        ->and($offered($other)['github'])->toBe([$environmentGitHub, 'environment']);

    // The bank turns the environment's GitHub off for its page, without one of its own.
    $this->withToken($key)->putJson('/api/v1/sign-in/social-providers/inherited/github', ['organization_id' => $bank->id, 'offered' => false])
        ->assertOk()
        ->assertJsonPath('data.not_inherited', ['github']);

    expect(array_keys($offered($bank)))->toBe(['discord'])
        ->and(array_keys($offered($other)))->toBe(['discord', 'github'])
        ->and(espAudit('social_provider.not_inherited')?->organization_id)->toBe($bank->id);

    // Acme turns its OWN GitHub off: the environment's does not stand in for it.
    $this->withToken($key)->postJson('/api/v1/sign-in/social-providers/'.$acmeGitHub.'/disable')
        ->assertOk()->assertJsonPath('data.enabled', false);

    expect(array_keys($offered($acme)))->toBe(['discord'])
        ->and(espAudit('social_provider.disabled')?->target_id)->toBe($acmeGitHub);

    // …and removing it brings the environment's back.
    $this->withToken($key)->deleteJson('/api/v1/sign-in/social-providers/'.$acmeGitHub)->assertNoContent();
    expect($offered($acme)['github'])->toBe([$environmentGitHub, 'environment']);

    $this->withToken($key)->putJson('/api/v1/sign-in/social-providers/inherited/github', ['organization_id' => $bank->id, 'offered' => true])->assertOk();
    expect(array_keys($offered($bank)))->toBe(['discord', 'github'])
        ->and(espAudit('social_provider.inherited')?->organization_id)->toBe($bank->id);
});

it('rotates a secret without changing the redirect URI, and never returns it', function (): void {
    ['key' => $key, 'keyId' => $keyId] = espTenant();

    $created = $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', ['environment_wide' => true, 'provider' => 'github', 'client_id' => 'old-id', 'client_secret' => 'old-secret'])->assertCreated();
    $id = $created->json('data.id');

    $updated = $this->withToken($key)->patchJson('/api/v1/sign-in/social-providers/'.$id, [
        'client_id' => 'new-id',
        'client_secret' => 'new-secret',
        'scopes' => ['read:user'],
    ])->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.callback_uri', $created->json('data.callback_uri'))
        ->assertJsonPath('data.scopes', ['read:user']);

    $config = app(Connections::class)->oauth2Config(Connection::query()->findOrFail($id));

    expect($updated->getContent())->not->toContain('new-secret')
        ->and($config->clientId)->toBe('new-id')
        ->and($config->clientSecret)->toBe('new-secret')
        ->and($config->scopes)->toBe(['read:user'])
        ->and(espAudit('social_provider.updated')?->actor_id)->toBe($keyId)
        ->and(espAudit('social_provider.updated')?->context['changed'] ?? null)->toBe(['client_id', 'client_secret', 'scopes'])
        ->and(json_encode(espAudit('social_provider.updated')?->context))->not->toContain('new-secret');

    // A blank secret keeps the one on file.
    $this->withToken($key)->patchJson('/api/v1/sign-in/social-providers/'.$id, ['client_secret' => ''])->assertOk();
    expect(app(Connections::class)->oauth2Config(Connection::query()->findOrFail($id))->clientSecret)->toBe('new-secret');
});

it('turns a provider off and on again, announcing it once on the way back', function (): void {
    ['key' => $key] = espTenant(['signin:read', 'signin:write', 'events:read']);

    $id = $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', ['environment_wide' => true, 'provider' => 'github', 'client_id' => 'c', 'client_secret' => 's'])->json('data.id');

    $this->withToken($key)->postJson('/api/v1/sign-in/social-providers/'.$id.'/disable')->assertOk()->assertJsonPath('data.status', 'inactive');
    expect(app(SignInProviders::class)->offeredTo(null))->toBe([]);

    $this->withToken($key)->postJson('/api/v1/sign-in/social-providers/'.$id.'/enable')->assertOk()->assertJsonPath('data.enabled', true);
    $this->withToken($key)->postJson('/api/v1/sign-in/social-providers/'.$id.'/enable')->assertOk();

    // The webhook event, once per activation: created, then turned back on — not for the no-op.
    $events = $this->withToken($key)->getJson('/api/v1/events?types[]=connection.activated')->assertOk()->json('data');

    expect($events)->toHaveCount(2)
        ->and($events[1]['payload']['id'] ?? null)->toBe($id)
        ->and($events[1]['payload']['provider'] ?? null)->toBe('github');
});

/**
 * @group security
 *
 * An environment's providers belong to it: a key for another environment cannot see, change
 * or remove them, and its organizations do not inherit them.
 */
it('keeps one environment\'s providers out of another\'s reach', function (): void {
    ['key' => $key] = espTenant();
    $acme = espOrg();

    $foreign = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_social_elsewhere'), function (): string {
        $connection = app(SignInProviders::class)->create(null, 'github', ConnectionType::OAuth2, 'GitHub', [
            'provider' => 'github', 'client_id' => 'theirs', 'client_secret' => 'theirs',
        ]);
        app(Connections::class)->activate(null, $connection->id);

        return $connection->id;
    });

    $this->withToken($key)->getJson('/api/v1/sign-in/social-providers')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($key)->getJson('/api/v1/sign-in/social-providers/offered?organization_id='.$acme->id)->assertOk()->assertJsonPath('data.providers', []);
    $this->withToken($key)->patchJson('/api/v1/sign-in/social-providers/'.$foreign, ['client_id' => 'stolen'])->assertNotFound();
    $this->withToken($key)->postJson('/api/v1/sign-in/social-providers/'.$foreign.'/disable')->assertNotFound();
    $this->withToken($key)->postJson('/api/v1/sign-in/social-providers/'.$foreign.'/enable')->assertNotFound();
    $this->withToken($key)->deleteJson('/api/v1/sign-in/social-providers/'.$foreign)->assertNotFound();

    // A reserved id that is taken ANYWHERE is refused rather than reused.
    $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', [
        'environment_wide' => true, 'provider' => 'github', 'client_id' => 'c', 'client_secret' => 's', 'reserved_id' => $foreign,
    ])->assertUnprocessable()->assertJsonPath('error', 'invalid_provider');

    $untouched = app(EnvironmentContext::class)->runAs(GenericEnvironment::of('env_social_elsewhere'), fn (): array => [
        Connection::query()->findOrFail($foreign)->status,
        app(Connections::class)->oauth2Config(Connection::query()->findOrFail($foreign))->clientId,
    ]);

    expect($untouched)->toBe([ConnectionStatus::Active, 'theirs']);
})->group('security');
