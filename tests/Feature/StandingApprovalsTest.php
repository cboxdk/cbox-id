<?php

declare(strict_types=1);

use App\Models\StandingApproval;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Standing approvals: Cbox Infra's CI deploys without a click (ADR-0017 Addendum A).
|--------------------------------------------------------------------------
|
| The organization's owner grants one for a machine client and one of the app's environments;
| the app then exchanges the client's own token for a plan-bound approval token, the shape a
| person's approval has, marked as the grant's. Everything else waits for a person.
*/

const SA_DIGEST = '3f1a9c0d7e2b4a6f8c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b';

const SA_EXCHANGE = 'urn:ietf:params:oauth:grant-type:token-exchange';

const SA_ACCESS_TOKEN = 'urn:ietf:params:oauth:token-type:access_token';

const SA_ENGINE = 'https://engine.infra.example';

/**
 * An organization with an owner and an admin, a CI machine client of it, the app (Dash)
 * that exchanges, and a management key.
 *
 * @return object{org: string, owner: string, admin: string, ci: Client, app: Client, appSecret: string, key: string}
 */
function saWorld(): object
{
    $org = app(Organizations::class)->create(new NewOrganization('Shop', 'shop-'.Str::lower(Str::random(5))))->id;
    $owner = app(Subjects::class)->create('owner-'.Str::random(5).'@shop.test', 'Owner Person', 'a-strong-unbreached-passphrase')->id;
    $admin = app(Subjects::class)->create('admin-'.Str::random(5).'@shop.test', 'Admin Person', 'a-strong-unbreached-passphrase')->id;
    app(Memberships::class)->add($org, $owner, MembershipRole::Owner);
    app(Memberships::class)->add($org, $admin, MembershipRole::Admin);

    $ci = app(ClientRegistry::class)->register(new NewClient(name: 'Shop CI', grantTypes: ['client_credentials'], organizationId: $org))->client;
    $registered = app(ClientRegistry::class)->register(new NewClient(name: 'Cbox Infra', grantTypes: ['authorization_code', SA_EXCHANGE], redirectUris: ['https://infra.example/callback']));
    $app = $registered->client;
    $app->forceFill(['authorization_details_types' => ['cbox_infra_change']])->save();

    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Infra', ['organizations:read', 'organizations:write'])->plaintext;

    return (object) ['org' => $org, 'owner' => $owner, 'admin' => $admin, 'ci' => $ci, 'app' => $app->refresh(), 'appSecret' => (string) $registered->secret, 'key' => $key];
}

/** @param array<string, mixed> $changes */
function saGrant(object $w, array $changes = []): TestResponse
{
    return test()->withToken($w->key)->postJson("/api/v1/organizations/{$w->org}/standing-approvals", [
        'client_id' => $w->ci->client_id,
        'app_client_id' => $w->app->client_id,
        'environment_ref' => 'env_staging',
        'environment_kind' => 'staging',
        'granted_by' => $w->owner,
        ...$changes,
    ]);
}

/** The CI client's own token, as it gets it with client credentials. */
function saCiToken(Client $ci): string
{
    return app(TokenIssuer::class)->issueClientCredentials($ci, [], 'https://infra.example/mcp')->token;
}

/**
 * The app exchanging a token for a standing approval of a plan.
 *
 * @param  array<string, mixed>  $entry
 */
function saExchange(object $w, string $subjectToken, array $entry = [], ?Client $as = null, ?string $secret = null): TestResponse
{
    $as ??= $w->app;

    return test()->post('/oauth/token', [
        'grant_type' => SA_EXCHANGE,
        'client_id' => $as->client_id,
        'client_secret' => $secret ?? $w->appSecret,
        'subject_token' => $subjectToken,
        'subject_token_type' => SA_ACCESS_TOKEN,
        'requested_token_type' => SA_ACCESS_TOKEN,
        'resource' => SA_ENGINE,
        'authorization_details' => json_encode([[
            'type' => 'cbox_infra_change',
            'plan_digest' => SA_DIGEST,
            'change_plan_id' => 'chg_1',
            'approval' => 'standing_grant',
            'environment_id' => 'env_staging',
            'environment_kind' => 'staging',
            ...$entry,
        ]]),
    ]);
}

/** @return array<string, mixed> */
function saClaims(string $jwt): array
{
    [, $payload] = explode('.', $jwt);

    return json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
}

it('lets the owner grant one, once, and lists it', function (): void {
    $w = saWorld();

    $id = saGrant($w)->assertCreated()
        ->assertJsonPath('data.client_id', $w->ci->client_id)
        ->assertJsonPath('data.environment_kind', 'staging')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.granted_by', $w->owner)
        ->json('data.id');

    // Already on: the same grant, recorded once.
    expect(saGrant($w)->json('data.id'))->toBe($id)
        ->and(AuditEntry::query()->where('action', 'standing_approval.granted')->count())->toBe(1);

    $entry = AuditEntry::query()->where('action', 'standing_approval.granted')->sole();
    expect($entry->target_id)->toBe($id)
        ->and($entry->organization_id)->toBe($w->org)
        ->and($entry->context['granted_by'])->toBe($w->owner)
        ->and($entry->context['environment_kind'])->toBe('staging');

    $this->withToken($w->key)->getJson("/api/v1/organizations/{$w->org}/standing-approvals")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id);
});

it('never grants one for a production environment', function (): void {
    $w = saWorld();

    saGrant($w, ['environment_kind' => 'production'])->assertStatus(422)->assertJsonPath('error', 'production_environment');
    saGrant($w, ['environment_kind' => 'Production'])->assertStatus(422)->assertJsonPath('error', 'production_environment');

    expect(StandingApproval::query()->count())->toBe(0);
});

it('takes a grant from the owner only', function (): void {
    $w = saWorld();

    saGrant($w, ['granted_by' => $w->admin])->assertForbidden()->assertJsonPath('error', 'not_owner');
    saGrant($w, ['granted_by' => null])->assertStatus(422)->assertJsonPath('error', 'not_owner');

    expect(StandingApproval::query()->count())->toBe(0);
});

it('grants one only for a machine client of the organization', function (): void {
    $w = saWorld();
    $other = app(Organizations::class)->create(new NewOrganization('Other', 'other-'.Str::lower(Str::random(5))))->id;
    $foreign = app(ClientRegistry::class)->register(new NewClient(name: 'Other CI', grantTypes: ['client_credentials'], organizationId: $other))->client;

    saGrant($w, ['client_id' => $foreign->client_id])->assertStatus(422)->assertJsonPath('error', 'unknown_client');
    saGrant($w, ['client_id' => 'no-such-client'])->assertStatus(422)->assertJsonPath('error', 'unknown_client');
    saGrant($w, ['app_client_id' => $w->ci->client_id])->assertStatus(422)->assertJsonPath('error', 'unknown_app');
});

it('issues the app a plan-bound token for the covered client, marked as the grant\'s', function (): void {
    $w = saWorld();
    $id = saGrant($w)->json('data.id');

    $answer = saExchange($w, saCiToken($w->ci))->assertOk();
    $claims = saClaims($answer->json('access_token'));

    expect($claims['sub'])->toBe($w->ci->client_id)
        ->and($claims['client_id'])->toBe($w->app->client_id)
        ->and($claims['org'])->toBe($w->org)
        ->and((array) $claims['aud'])->toContain(SA_ENGINE)
        ->and($claims)->not->toHaveKey('act')
        ->and($claims['authorization_details'])->toBe([[
            'type' => 'cbox_infra_change',
            'plan_digest' => SA_DIGEST,
            'change_plan_id' => 'chg_1',
            'approval' => 'standing_grant',
            'standing_grant_id' => $id,
            'client_id' => $w->ci->client_id,
            'environment_id' => 'env_staging',
            'environment_kind' => 'staging',
        ]])
        ->and($answer->json('authorization_details.0.standing_grant_id'))->toBe($id);

    $grant = StandingApproval::query()->findOrFail($id);
    expect($grant->uses)->toBe(1)->and($grant->last_used_at)->not->toBeNull();

    $used = AuditEntry::query()->where('action', 'standing_approval.used')->sole();
    expect($used->actor_id)->toBe($w->ci->client_id)
        ->and($used->context['plan_digest'])->toBe(SA_DIGEST)
        ->and($used->context['token_id'])->toBe($claims['jti']);
});

it('refuses a production-class environment at the exchange too', function (): void {
    $w = saWorld();
    saGrant($w);

    saExchange($w, saCiToken($w->ci), ['environment_kind' => 'production'])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    // A grant row that says production (written around the rules) covers nothing either.
    StandingApproval::query()->update(['environment_kind' => 'production']);
    saExchange($w, saCiToken($w->ci), ['environment_kind' => 'production'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    saExchange($w, saCiToken($w->ci))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(AuditEntry::query()->where('action', 'standing_approval.used')->count())->toBe(0);
});

it('refuses after the grant is revoked', function (): void {
    $w = saWorld();
    $id = saGrant($w)->json('data.id');
    saExchange($w, saCiToken($w->ci))->assertOk();

    $this->withToken($w->key)->deleteJson("/api/v1/organizations/{$w->org}/standing-approvals/{$id}", ['reason' => 'owner_turned_off'])->assertNoContent();

    saExchange($w, saCiToken($w->ci))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    $revoked = AuditEntry::query()->where('action', 'standing_approval.revoked')->sole();
    expect($revoked->context['reason'])->toBe('owner_turned_off')
        ->and(StandingApproval::query()->findOrFail($id)->revoked_at)->not->toBeNull();

    // Turning it off again changes nothing and records nothing.
    $this->withToken($w->key)->deleteJson("/api/v1/organizations/{$w->org}/standing-approvals/{$id}")->assertNoContent();
    expect(AuditEntry::query()->where('action', 'standing_approval.revoked')->count())->toBe(1);
});

it('refuses a client, an environment, a kind or an app the grant does not cover', function (): void {
    $w = saWorld();
    saGrant($w);

    $uncovered = app(ClientRegistry::class)->register(new NewClient(name: 'Other CI of the shop', grantTypes: ['client_credentials'], organizationId: $w->org))->client;
    saExchange($w, saCiToken($uncovered))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    saExchange($w, saCiToken($w->ci), ['environment_id' => 'env_other'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    saExchange($w, saCiToken($w->ci), ['environment_kind' => 'development'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    $otherApp = app(ClientRegistry::class)->register(new NewClient(name: 'Another app', grantTypes: [SA_EXCHANGE]));
    $otherApp->client->forceFill(['authorization_details_types' => ['cbox_infra_change']])->save();
    saExchange($w, saCiToken($w->ci), as: $otherApp->client->refresh(), secret: (string) $otherApp->secret)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(AuditEntry::query()->where('action', 'standing_approval.used')->count())->toBe(0);
});

it('binds exactly the digest asked for, and refuses one that is not a digest', function (): void {
    $w = saWorld();
    saGrant($w);
    $other = str_repeat('ab', 32);

    $claims = saClaims(saExchange($w, saCiToken($w->ci), ['plan_digest' => $other])->assertOk()->json('access_token'));
    expect($claims['authorization_details'][0]['plan_digest'])->toBe($other);

    saExchange($w, saCiToken($w->ci), ['plan_digest' => 'not-a-digest'])->assertStatus(400)->assertJsonPath('error', 'invalid_authorization_details');
});

it('does not change a person\'s exchange, and never issues for a person\'s token as a standing approval', function (): void {
    $w = saWorld();
    saGrant($w);
    $person = app(TokenIssuer::class)->issueForUser($w->app, $w->owner, $w->org, ['openid'])->token;

    // The person's token goes the framework's way; the marker restricts nothing there and
    // the answer is the person's approval, never the grant's.
    $claims = saClaims(saExchange($w, $person)->assertOk()->json('access_token'));
    expect($claims['sub'])->toBe($w->owner)
        ->and($claims['authorization_details'][0])->not->toHaveKey('standing_grant_id');

    expect(AuditEntry::query()->where('action', 'standing_approval.used')->count())->toBe(0);
});
