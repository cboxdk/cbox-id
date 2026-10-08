<?php

declare(strict_types=1);

use App\Actions\Branding\SetAppearance;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Console\ConsoleScope;
use App\Platform\SelfServiceSignup;
use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\AccessControl\ManifestSyncService;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DnsResolver;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Testing\FakeDnsResolver;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\BreachedPasswordCheck;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\NeverBreachedCheck;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Migration\Models\LegacyLoginDeclarationRecord;
use Cbox\Id\Migration\ValueObjects\LegacyLoginDeclaration;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\EnvironmentDomains;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Whitelabel\Actions\SaveBranding;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| How people sign in, as actions: one change, whichever door asks for it.
|--------------------------------------------------------------------------
|
| The sign-in rules, self-service sign-up, social providers, frontend keys, SAML apps,
| the legacy-login approval, branding and the custom domain — each a management API
| endpoint and the console's own write, running the same action with the same rules and
| the same trail. Every request here is a tenant environment's, on its own host.
*/

beforeEach(function (): void {
    app()->instance(BreachedPasswordCheck::class, new NeverBreachedCheck);
    config()->set('cbox-id.migration.verify_url', false);
});

/**
 * A provisioned tenant environment this test's host resolves to, with its workspace.
 *
 * @return array{environment: Environment, workspaceId: string, ownerId: string}
 */
function siaTenant(): array
{
    multiTenantDeployment();
    $tenant = provisionAccount();

    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));

    return [
        'environment' => $tenant['environment']->refresh(),
        'workspaceId' => $tenant['organization']->id,
        'ownerId' => $tenant['subjectId'],
    ];
}

/**
 * A management key for $environment holding exactly $scopes.
 *
 * @param  list<string>  $scopes
 * @return array{0: string, 1: string} the key, and its id
 */
function siaKey(Environment $environment, array $scopes): array
{
    $issued = app(EnvironmentApiKeys::class)->issue($environment->id, 'Sign-in worker', $scopes);

    return [$issued->plaintext, (string) $issued->key->id];
}

function siaOrg(string $name = 'Tenant Co'): Organization
{
    return app(Organizations::class)->create(new NewOrganization($name, Str::slug($name).'-'.Str::lower(Str::random(4))));
}

/** The newest entry of $action on the current environment's trail. */
function siaAudit(string $action): ?AuditEntry
{
    return AuditEntry::query()->where('action', $action)->orderByDesc('sequence')->first();
}

/** The newest entry of $action on a workspace's trail, in the platform root. */
function siaWorkspaceAudit(string $action): ?AuditEntry
{
    return app(PlatformRoot::class)->run(fn (): ?AuditEntry => AuditEntry::query()->where('action', $action)->orderByDesc('id')->first());
}

/*
|--------------------------------------------------------------------------
| Sign-in rules
|--------------------------------------------------------------------------
*/

it('reads and changes the environment baseline, and records it as the key', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key, $keyId] = siaKey($environment, ['signin:read', 'signin:write']);

    $this->withToken($key)->getJson('/api/v1/sign-in/policy')
        ->assertOk()
        ->assertJsonPath('data.organization_id', null)
        ->assertJsonPath('data.inheriting', false);

    $this->withToken($key)->patchJson('/api/v1/sign-in/policy', ['min_length' => 16, 'mfa' => 'required'])
        ->assertOk()
        ->assertJsonPath('data.policy.min_length', 16)
        ->assertJsonPath('data.policy.mfa', 'required');

    // Only what was sent changed: the rest of the baseline is what it was.
    $baseline = app(AuthPolicies::class)->forEnvironment();

    expect($baseline->minLength)->toBe(16)
        ->and($baseline->mfa)->toBe(MfaRequirement::Required)
        ->and($baseline->requireBreachCheck)->toBeTrue();

    $entry = siaAudit('auth_policy.updated');

    expect($entry?->actor_type)->toBe(ActorType::Service)
        ->and($entry?->actor_id)->toBe($keyId)
        ->and($entry?->target_type)->toBe('environment')
        ->and($entry?->context['policy']['min_length'] ?? null)->toBe(16);
})->group('security');

it('tightens one organization, and refuses an override that loosens the baseline — every field', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key] = siaKey($environment, ['signin:read', 'signin:write']);
    $org = siaOrg();

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(minLength: 14, sso: SsoEnforcement::Preferred));

    $this->withToken($key)->patchJson('/api/v1/sign-in/policy', ['organization_id' => $org->id, 'min_length' => 20])
        ->assertOk()
        ->assertJsonPath('data.organization_id', $org->id)
        ->assertJsonPath('data.inheriting', false)
        ->assertJsonPath('data.override.min_length', 20)
        ->assertJsonPath('data.baseline.min_length', 14);

    $refused = $this->withToken($key)->patchJson('/api/v1/sign-in/policy', [
        'organization_id' => $org->id,
        'min_length' => 10,
        'sso' => 'off',
    ])->assertUnprocessable()->assertJsonPath('error', 'loosens_environment_baseline');

    // Both floors named in the one refusal, so nobody fixes one and is refused for the other.
    expect($refused->json('message'))->toContain('at least 14 characters')->toContain('"preferred"')
        ->and(app(AuthPolicies::class)->overrideFor($org->id)?->minLength)->toBe(20);

    $this->withToken($key)->getJson('/api/v1/sign-in/policy?organization_id='.$org->id)
        ->assertOk()
        ->assertJsonPath('data.policy.min_length', 20);
})->group('security');

it('ends password sessions when a key requires SSO — through the decorated contract', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key] = siaKey($environment, ['signin:write']);
    $org = siaOrg();

    $subject = app(Subjects::class)->create('member@tenant.example', 'Member', 'a-strong-unbreached-passphrase');
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);

    $this->withToken($key)->patchJson('/api/v1/sign-in/policy', ['organization_id' => $org->id, 'sso' => 'required'])->assertOk();

    expect(app(SessionManager::class)->active($session->id))->toBeNull();
})->group('security');

it('drops an organization override, and 404s an organization that is not here', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key] = siaKey($environment, ['signin:write']);
    $org = siaOrg();

    app(AuthPolicies::class)->setForOrganization($org->id, new AuthPolicy(minLength: 22));

    $this->withToken($key)->deleteJson('/api/v1/sign-in/policy/organizations/'.$org->id)->assertNoContent();

    expect(app(AuthPolicies::class)->overrideFor($org->id))->toBeNull()
        ->and(siaAudit('auth_policy.inherited')?->target_id)->toBe($org->id);

    $this->withToken($key)->deleteJson('/api/v1/sign-in/policy/organizations/org_elsewhere')
        ->assertNotFound()
        ->assertJsonPath('error', 'not_found');

    $this->withToken($key)->patchJson('/api/v1/sign-in/policy', ['organization_id' => 'org_elsewhere', 'min_length' => 20])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'organization_not_found');
})->group('security');

it('needs signin:write to change how people sign in, and signin:read to look', function (): void {
    ['environment' => $environment] = siaTenant();
    [$reader] = siaKey($environment, ['signin:read']);
    [$stranger] = siaKey($environment, ['apis:read']);

    $this->withToken($reader)->getJson('/api/v1/sign-in/policy')->assertOk();
    $this->withToken($reader)->patchJson('/api/v1/sign-in/policy', ['min_length' => 20])->assertForbidden();
    $this->withToken($reader)->putJson('/api/v1/sign-in/self-service-signup', ['enabled' => true])->assertForbidden();
    $this->withToken($reader)->postJson('/api/v1/legacy-login/approve')->assertForbidden();
    $this->withToken($stranger)->getJson('/api/v1/sign-in/policy')->assertForbidden();

    expect(app(AuthPolicies::class)->forEnvironment()->minLength)->toBe(12);
})->group('security');

it('writes the same sign-in rules entry from the console, as the person', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = siaTenant();
    actAsEnvironmentAdmin($ownerId, $environment->id);

    test()->from(route('environment.auth-policy'))
        ->put(route('environment.auth-policy.update'), [
            'minLength' => 18, 'requireBreachCheck' => true, 'maxAgeDays' => '', 'reuseHistory' => 0,
            'mfa' => 'optional', 'sso' => 'off', 'lockoutThreshold' => '',
        ])
        ->assertSessionHasNoErrors();

    $entry = siaAudit('auth_policy.updated');

    expect(app(AuthPolicies::class)->forEnvironment()->minLength)->toBe(18)
        ->and($entry?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entry?->actor_id)->toBe($ownerId)
        ->and($entry?->target_type)->toBe('environment')
        ->and($entry?->context['policy']['min_length'] ?? null)->toBe(18);
})->group('security');

/*
|--------------------------------------------------------------------------
| Self-service sign-up
|--------------------------------------------------------------------------
*/

it('switches self-service sign-up, on the workspace trail, as the console does', function (): void {
    ['environment' => $environment, 'workspaceId' => $workspaceId, 'ownerId' => $ownerId] = siaTenant();
    [$key, $keyId] = siaKey($environment, ['signin:write']);

    // The console first, so its entry is the person's…
    actAsEnvironmentAdmin($ownerId, $environment->id);
    test()->from(route('environment.auth-policy'))
        ->put(route('environment.auth-policy.self-service-signup'), ['enabled' => true])
        ->assertSessionHasNoErrors();

    $byConsole = siaWorkspaceAudit('environment.self_service_signup_enabled');

    // …then the key switches it off and on again.
    $this->withToken($key)->putJson('/api/v1/sign-in/self-service-signup', ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.decided_here', true);

    expect(SelfServiceSignup::enabledFor($environment->refresh()))->toBeFalse();

    $this->withToken($key)->putJson('/api/v1/sign-in/self-service-signup', ['enabled' => true])->assertOk();

    $byKey = siaWorkspaceAudit('environment.self_service_signup_enabled');

    expect($byConsole?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($byConsole?->actor_id)->toBe($ownerId)
        ->and($byKey?->actor_type)->toBe(ActorType::Service)
        ->and($byKey?->actor_id)->toBe($keyId)
        // The same entry otherwise: the same trail, the same target.
        ->and([$byKey?->scope, $byKey?->target_type, $byKey?->target_id])
        ->toBe([$byConsole?->scope, $byConsole?->target_type, $byConsole?->target_id])
        ->and($byKey?->scope)->toBe($workspaceId);
});

/*
|--------------------------------------------------------------------------
| Social sign-in providers
|--------------------------------------------------------------------------
*/

it('enables a social provider without ever returning its secret, and lists it', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key, $keyId] = siaKey($environment, ['signin:read', 'signin:write']);
    $org = siaOrg();

    $created = $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', [
        'organization_id' => $org->id,
        'provider' => 'github',
        'client_id' => 'gh-client',
        'client_secret' => 'gh-very-secret',
    ])->assertCreated()
        ->assertJsonPath('data.provider', 'github')
        ->assertJsonPath('data.protocol', 'oauth2')
        ->assertJsonPath('data.status', 'active');

    expect($created->getContent())->not->toContain('gh-very-secret')
        ->and($created->json('data.callback_uri'))->toEndWith('/sso/oauth2/'.$created->json('data.id').'/callback')
        ->and(app(Connections::class)->catalogueProvidersFor($org->id))->toHaveCount(1)
        // The framework's own entry, attributed to the key.
        ->and(siaAudit('connection.activated')?->actor_id)->toBe($keyId);

    $this->withToken($key)->getJson('/api/v1/sign-in/social-providers?organization_id='.$org->id)
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses a social provider it cannot offer, twice, or without what it needs', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key] = siaKey($environment, ['signin:write']);
    $org = siaOrg();
    $enable = fn (array $body) => $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', [
        'organization_id' => $org->id, 'client_id' => 'client', 'client_secret' => 'secret', ...$body,
    ]);

    $enable(['provider' => 'myspace'])->assertUnprocessable()->assertJsonPath('error', 'unknown_provider');
    $enable(['provider' => 'github', 'client_secret' => ''])->assertUnprocessable()->assertJsonPath('error', 'client_secret_required');
    $enable(['provider' => 'apple', 'client_secret' => null])->assertUnprocessable()->assertJsonPath('error', 'missing_parameters');
    $enable(['provider' => 'github', 'organization_id' => 'org_elsewhere'])->assertUnprocessable()->assertJsonPath('error', 'organization_not_found');

    $enable(['provider' => 'github'])->assertCreated();
    $enable(['provider' => 'github'])->assertUnprocessable()->assertJsonPath('error', 'already_enabled');
});

it('removes only a social provider: never an SSO connection, never another organization\'s', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key] = siaKey($environment, ['signin:write']);
    $org = siaOrg();
    $other = siaOrg('Other Co');

    $github = $this->withToken($key)->postJson('/api/v1/sign-in/social-providers', [
        'organization_id' => $org->id, 'provider' => 'github', 'client_id' => 'c', 'client_secret' => 's',
    ])->assertCreated()->json('data.id');

    $sso = app(Connections::class)->create($org->id, ConnectionType::Oidc, 'Acme Okta', [
        'issuer' => 'https://acme.okta.example', 'client_id' => 'x', 'client_secret' => 'y',
        'authorization_endpoint' => 'https://acme.okta.example/authorize',
        'token_endpoint' => 'https://acme.okta.example/token',
        'jwks_uri' => 'https://acme.okta.example/keys',
    ]);

    $this->withToken($key)->deleteJson('/api/v1/sign-in/social-providers/'.$sso->id)->assertNotFound();
    $this->withToken($key)->deleteJson('/api/v1/sign-in/social-providers/'.$github.'?organization_id='.$other->id)->assertNotFound();

    $this->withToken($key)->deleteJson('/api/v1/sign-in/social-providers/'.$github)->assertNoContent();
    $this->withToken($key)->deleteJson('/api/v1/sign-in/social-providers/'.$github)->assertNotFound();

    expect(app(Connections::class)->byId($sso->id))->not->toBeNull()
        ->and(siaAudit('social_provider.removed')?->target_id)->toBe($github);
})->group('security');

/*
|--------------------------------------------------------------------------
| Frontend keys
|--------------------------------------------------------------------------
*/

it('creates, lists, re-scopes and revokes a frontend key', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key] = siaKey($environment, ['frontend_keys:read', 'frontend_keys:write']);

    $created = $this->withToken($key)->postJson('/api/v1/frontend-keys', [
        'name' => 'Web app', 'mode' => 'test', 'origins' => ['https://app.example.com'],
    ])->assertCreated()
        ->assertJsonPath('data.origins', ['https://app.example.com'])
        ->assertJsonPath('data.active', true);

    $id = $created->json('data.id');

    expect($created->json('data.key'))->toBeString()->not->toBe('');

    $this->withToken($key)->getJson('/api/v1/frontend-keys')->assertOk()->assertJsonCount(1, 'data');

    $this->withToken($key)->putJson("/api/v1/frontend-keys/{$id}/origins", ['origins' => ['https://staging.example.com']])
        ->assertOk()
        ->assertJsonPath('data.origins', ['https://staging.example.com']);

    $this->withToken($key)->putJson("/api/v1/frontend-keys/{$id}/origins", ['origins' => ['http://evil.example.com']])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'unusable_origin');

    $this->withToken($key)->deleteJson("/api/v1/frontend-keys/{$id}")->assertNoContent();

    $this->withToken($key)->putJson("/api/v1/frontend-keys/{$id}/origins", ['origins' => ['https://late.example.com']])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'key_revoked');

    $this->withToken($key)->deleteJson('/api/v1/frontend-keys/fk_missing')->assertNotFound();
    $this->withToken($key)->postJson('/api/v1/frontend-keys', ['name' => 'Nowhere', 'mode' => 'test', 'origins' => []])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'validation_failed');
});

it('needs frontend_keys:write to make or revoke a frontend key', function (): void {
    ['environment' => $environment] = siaTenant();
    [$reader] = siaKey($environment, ['frontend_keys:read']);

    $this->withToken($reader)->postJson('/api/v1/frontend-keys', ['name' => 'X', 'mode' => 'test', 'origins' => ['https://x.example']])
        ->assertForbidden();
})->group('security');

/*
|--------------------------------------------------------------------------
| SAML applications
|--------------------------------------------------------------------------
*/

it('registers, reads, changes and removes a SAML app — the certificate is write-only', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key, $keyId] = siaKey($environment, ['saml_apps:read', 'saml_apps:write']);

    $created = $this->withToken($key)->postJson('/api/v1/saml-apps', [
        'entity_id' => 'https://sp.example.com',
        'acs_url' => 'https://sp.example.com/acs',
        'want_authn_requests_signed' => true,
        'certificate' => '-----BEGIN CERTIFICATE-----MIIBnot-a-real-cert-----END CERTIFICATE-----',
        'attribute_mappings' => [['attribute' => 'email', 'field' => 'email']],
    ])->assertCreated()
        ->assertJsonPath('data.has_certificate', true)
        ->assertJsonPath('data.name_id_attribute', 'email')
        ->assertJsonPath('data.attribute_mappings', [['attribute' => 'email', 'field' => 'email']]);

    $id = $created->json('data.id');

    expect($created->getContent())->not->toContain('MIIBnot-a-real-cert')
        ->and(siaAudit('saml_app.registered')?->actor_id)->toBe($keyId);

    $this->withToken($key)->getJson("/api/v1/saml-apps/{$id}")->assertOk()->assertJsonPath('data.entity_id', 'https://sp.example.com');
    $this->withToken($key)->getJson('/api/v1/saml-apps')->assertOk()->assertJsonCount(1, 'data');

    // No certificate in the change keeps the one on file, so the signed flag stays honest.
    $this->withToken($key)->patchJson("/api/v1/saml-apps/{$id}", ['name_id_attribute' => 'sub'])
        ->assertOk()
        ->assertJsonPath('data.name_id_attribute', 'sub')
        ->assertJsonPath('data.has_certificate', true);

    $this->withToken($key)->patchJson("/api/v1/saml-apps/{$id}", ['acs_url' => 'http://sp.example.com/acs'])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'insecure_acs_url');

    $this->withToken($key)->deleteJson("/api/v1/saml-apps/{$id}")->assertNoContent();
    $this->withToken($key)->getJson("/api/v1/saml-apps/{$id}")->assertNotFound();

    expect(siaAudit('saml_app.deleted')?->target_id)->toBe($id);
});

it('refuses a second SAML app with the same entity id, and a signed one with no certificate', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key] = siaKey($environment, ['saml_apps:write']);
    $register = fn (array $body) => $this->withToken($key)->postJson('/api/v1/saml-apps', [
        'entity_id' => 'https://sp.example.com', 'acs_url' => 'https://sp.example.com/acs', ...$body,
    ]);

    $register(['want_authn_requests_signed' => true])->assertUnprocessable()->assertJsonPath('error', 'certificate_required');
    $register([])->assertCreated();
    $register([])->assertUnprocessable()->assertJsonPath('error', 'entity_id_taken');
    $this->withToken($key)->patchJson('/api/v1/saml-apps/sp_missing', ['name_id_attribute' => 'sub'])->assertNotFound();
});

it('records a SAML app registered from the console the same way, as the person', function (): void {
    ['environment' => $environment, 'ownerId' => $ownerId] = siaTenant();
    actAsEnvironmentAdmin($ownerId, $environment->id);

    test()->post(route('environment.sso-providers.store'), [
        'entityId' => 'https://console-sp.example.com',
        'acsUrl' => 'https://console-sp.example.com/acs',
        'nameIdFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        'nameIdAttribute' => 'email',
        'attributeMappings' => [['key' => 'email', 'value' => 'email']],
        'wantAuthnRequestsSigned' => false,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $entry = siaAudit('saml_app.registered');

    expect($entry?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($entry?->actor_id)->toBe($ownerId)
        ->and($entry?->context['entity_id'] ?? null)->toBe('https://console-sp.example.com');
});

/*
|--------------------------------------------------------------------------
| Legacy login
|--------------------------------------------------------------------------
*/

function siaDeclareLegacy(): void
{
    $clientId = app(ClientRegistry::class)->register(new NewClient('Acme Web'))->client->client_id;

    app(ManifestSyncService::class)->sync($clientId, new Manifest(
        version: 'v'.mt_rand(),
        permissions: [],
        roles: [],
        legacyLogin: new LegacyLoginDeclaration('https://legacy.acme.test/verify', str_repeat('s', 40)),
    ));
}

it('probes, approves and withdraws the legacy login, recording who', function (): void {
    Http::fake(['*' => Http::response(['email' => 'ada@legacy.test', 'name' => 'Ada'], 200)]);

    ['environment' => $environment] = siaTenant();
    [$key, $keyId] = siaKey($environment, ['signin:read', 'signin:write']);

    $this->withToken($key)->getJson('/api/v1/legacy-login')->assertOk()->assertJsonPath('data.declared', false);
    $this->withToken($key)->postJson('/api/v1/legacy-login/probe', ['email' => 'ada@legacy.test'])
        ->assertUnprocessable()->assertJsonPath('error', 'no_declaration');
    $this->withToken($key)->postJson('/api/v1/legacy-login/approve')
        ->assertUnprocessable()->assertJsonPath('error', 'no_declaration');

    siaDeclareLegacy();

    $this->withToken($key)->getJson('/api/v1/legacy-login')
        ->assertOk()
        ->assertJsonPath('data.declared_by', 'Acme Web')
        ->assertJsonPath('data.approved', false);

    expect($this->withToken($key)->postJson('/api/v1/legacy-login/probe', ['email' => 'ada@legacy.test'])->assertOk()->json('data.result'))
        ->toContain('The integration works');

    $this->withToken($key)->postJson('/api/v1/legacy-login/probe', ['email' => 'not an address'])
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['errors' => ['email']]);

    $this->withToken($key)->postJson('/api/v1/legacy-login/approve')
        ->assertOk()
        ->assertJsonPath('data.approved', true)
        ->assertJsonPath('data.approved_by', $keyId);

    expect(siaAudit('legacy_login.approved')?->actor_id)->toBe($keyId);

    $this->withToken($key)->postJson('/api/v1/legacy-login/revoke')->assertOk()->assertJsonPath('data.approved', false);

    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeFalse()
        ->and(siaAudit('legacy_login.revoked')?->actor_type)->toBe(ActorType::Service);
})->group('security');

/*
|--------------------------------------------------------------------------
| Branding
|--------------------------------------------------------------------------
*/

it('themes the environment default and one organization, and refuses an unreadable palette', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key] = siaKey($environment, ['branding:read', 'branding:write']);
    $org = siaOrg();

    $this->withToken($key)->getJson('/api/v1/branding/appearance')->assertOk()->assertJsonPath('data.customized', false);

    $this->withToken($key)->putJson('/api/v1/branding/appearance', [
        'theme' => ['preset' => 'cbox', 'light' => ['primary' => '#123456']],
        'logo' => 'https://cdn.example.com/logo.png',
    ])->assertOk()
        ->assertJsonPath('data.customized', true)
        ->assertJsonPath('data.theme.light.primary', '#123456')
        ->assertJsonPath('data.logo', 'https://cdn.example.com/logo.png');

    expect($environment->refresh()->settings['brand_color'] ?? null)->toBe('#123456');

    // A logo left out stays; only null removes it.
    $this->withToken($key)->putJson('/api/v1/branding/appearance', ['theme' => ['preset' => 'cbox']])
        ->assertOk()
        ->assertJsonPath('data.logo', 'https://cdn.example.com/logo.png');

    $this->withToken($key)->putJson('/api/v1/branding/appearance', ['organization_id' => $org->id, 'theme' => ['light' => ['primary' => '#654321']]])
        ->assertOk()
        ->assertJsonPath('data.organization_id', $org->id);

    $this->withToken($key)->getJson('/api/v1/branding/appearance?organization_id='.$org->id)
        ->assertOk()
        ->assertJsonPath('data.theme.light.primary', '#654321');

    $this->withToken($key)->putJson('/api/v1/branding/appearance', ['theme' => ['light' => ['background' => '#000000', 'foreground' => '#000000']]])
        ->assertUnprocessable()->assertJsonPath('error', 'unreadable_palette');
    $this->withToken($key)->putJson('/api/v1/branding/appearance', ['theme' => ['preset' => 'cbox'], 'logo' => 'http://cdn.example.com/logo.png'])
        ->assertUnprocessable()->assertJsonPath('error', 'insecure_logo');
    $this->withToken($key)->putJson('/api/v1/branding/appearance', ['organization_id' => 'org_elsewhere', 'theme' => ['preset' => 'cbox']])
        ->assertUnprocessable()->assertJsonPath('error', 'organization_not_found');
});

it('saves white-label branding through the module\'s own action', function (): void {
    ['environment' => $environment] = siaTenant();
    [$key] = siaKey($environment, ['branding:read', 'branding:write']);

    expect(app(ActionRegistry::class)->named('branding.whitelabel.set')->class)->toBe(SaveBranding::class);

    $this->withToken($key)->putJson('/api/v1/branding/whitelabel', [
        'palette' => ['primary' => '#0a2540'],
        'app_name' => 'Acme Identity',
    ])->assertOk()
        ->assertJsonPath('data.palette.primary', '#0a2540')
        ->assertJsonPath('data.app_name', 'Acme Identity');

    // A field left out keeps its value.
    $this->withToken($key)->putJson('/api/v1/branding/whitelabel', ['email_from_name' => 'Acme'])->assertOk();

    $this->withToken($key)->getJson('/api/v1/branding/whitelabel')
        ->assertOk()
        ->assertJsonPath('data.app_name', 'Acme Identity')
        ->assertJsonPath('data.email_from_name', 'Acme')
        ->assertJsonPath('data.palette.primary', '#0a2540');

    $this->withToken($key)->putJson('/api/v1/branding/whitelabel', ['palette' => ['primary' => 'red', 'accent' => 'blue']])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'invalid_colour');
});

it('keeps an organization administrator on their own organization, whichever door', function (): void {
    // The console page passes the scope's organization; an action reached with any other —
    // or with none, the default every tenant inherits — is a forged request.
    [, $org] = actingAsRole(MembershipRole::Owner);
    $other = app(Organizations::class)->create(new NewOrganization('Other', 'other-forged'));
    $principal = new ConsoleSessionPrincipal(app(ConsoleScope::class));

    // Another organization answers as an unknown one would — nothing confirms it exists.
    expect(fn () => app(ActionRunner::class)->run(SetAppearance::class, $principal, ['organization_id' => $other->id, 'theme' => ['preset' => 'cbox']]))
        ->toThrow(ActionRefused::class, 'No organization with that organization_id exists in this environment.');

    // None at all is the environment's default every tenant inherits: refused outright.
    expect(fn () => app(ActionRunner::class)->run(SetAppearance::class, $principal, ['organization_id' => null, 'theme' => ['preset' => 'cbox']]))
        ->toThrow(AuthorizationException::class);

    app(ActionRunner::class)->run(SetAppearance::class, $principal, ['organization_id' => $org->id, 'theme' => ['preset' => 'cbox']]);

    expect($org->refresh()->settings['appearance'] ?? null)->toBeArray()
        ->and($other->refresh()->settings['appearance'] ?? null)->toBeNull();
})->group('security');

/*
|--------------------------------------------------------------------------
| Custom domain
|--------------------------------------------------------------------------
*/

it('adds and verifies this environment\'s custom domain, on the workspace trail', function (): void {
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    $dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $dns);
    app()->forgetInstance(EnvironmentDomains::class);

    ['environment' => $environment, 'workspaceId' => $workspaceId] = siaTenant();
    [$key, $keyId] = siaKey($environment, ['domains:read', 'domains:write']);

    $this->withToken($key)->getJson('/api/v1/domains')->assertOk()->assertJsonPath('data.pending', null);

    $this->withToken($key)->postJson('/api/v1/domains/verify')->assertUnprocessable()->assertJsonPath('error', 'no_pending_domain');
    $this->withToken($key)->postJson('/api/v1/domains', ['domain' => 'acme.cboxid.com'])
        ->assertUnprocessable()->assertJsonPath('error', 'invalid_domain');

    $pending = $this->withToken($key)->postJson('/api/v1/domains', ['domain' => 'id.acme.com'])
        ->assertOk()
        ->assertJsonPath('data.pending.domain', 'id.acme.com')
        ->assertJsonPath('data.pending.record_name', '_cbox-id-challenge.id.acme.com')
        ->json('data.pending');

    $this->withToken($key)->postJson('/api/v1/domains/verify')->assertUnprocessable()->assertJsonPath('error', 'dns_not_propagated');

    $dns->publish($pending['record_name'], $pending['record_value']);

    // The last request on this host: once verified, the environment answers on its new one.
    $this->withToken($key)->postJson('/api/v1/domains/verify')->assertOk()->assertJsonPath('data.domain', 'id.acme.com');

    $entry = siaWorkspaceAudit('organization.custom_domain_verified');

    expect($environment->refresh()->domain)->toBe('id.acme.com')
        ->and($entry?->scope)->toBe($workspaceId)
        ->and($entry?->actor_type)->toBe(ActorType::Service)
        ->and($entry?->actor_id)->toBe($keyId)
        ->and($entry?->context['domain'] ?? null)->toBe('id.acme.com');
});

it('removes this environment\'s custom domain, and needs domains:write to', function (): void {
    ['environment' => $environment, 'workspaceId' => $workspaceId] = siaTenant();
    [$reader] = siaKey($environment, ['domains:read']);
    [$writer, $writerId] = siaKey($environment, ['domains:write']);

    $this->withToken($reader)->deleteJson('/api/v1/domains')->assertForbidden();

    expect($environment->refresh()->domain)->not->toBeNull();

    $this->withToken($writer)->deleteJson('/api/v1/domains')->assertNoContent();

    expect($environment->refresh()->domain)->toBeNull()
        ->and(siaWorkspaceAudit('organization.custom_domain_removed')?->actor_id)->toBe($writerId)
        ->and(siaWorkspaceAudit('organization.custom_domain_removed')?->scope)->toBe($workspaceId);
})->group('security');
