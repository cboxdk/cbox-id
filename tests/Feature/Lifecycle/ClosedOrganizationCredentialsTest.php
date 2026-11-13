<?php

declare(strict_types=1);

use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Crypto\Contracts\TokenSigner;
use Cbox\Id\Kernel\Crypto\Enums\SigningAlg;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\RefreshTokens;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

/**
 * A SUSPENDED OR ARCHIVED ORGANIZATION IS CLOSED TO EVERY CREDENTIAL THAT SPEAKS FOR IT.
 *
 * Suspension refused members at the sign-in doors and the console; the token endpoint, the
 * introspector and SCIM never asked. A refresh token kept minting tokens for the closed
 * organization, its own backend kept getting client_credentials tokens and reading its vault,
 * and its identity provider kept provisioning people through SCIM. Each is refused while
 * the organization is closed, and comes back when it is reactivated: nothing is revoked, so
 * nobody has to sign in again.
 */
function cocOrg(): Organization
{
    return app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.uniqid()));
}

function cocClose(Organization $org, string $how): void
{
    match ($how) {
        'suspended' => app(Organizations::class)->suspend($org->id, 'sub_operator'),
        'archived' => app(Organizations::class)->archive($org->id, 'sub_operator'),
    };
}

function cocPublicApp(string $name): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: $name,
        type: ClientType::Public,
        redirectUris: ['https://'.strtolower($name).'.test/callback'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'offline_access'],
        firstParty: true,
    ))->client;
}

it('refuses to refresh a token bound to a closed organization, without spending it (#27)', function (string $how): void {
    $org = cocOrg();
    $person = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    app(Memberships::class)->add($org->id, $person->id, MembershipRole::Admin);
    $app = cocPublicApp('Ledger');
    $refresh = app(RefreshTokens::class)->issue($app, $person->id, $org->id, ['openid', 'offline_access']);

    cocClose($org, $how);

    $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $app->client_id, 'refresh_token' => $refresh])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    // Not consumed: reactivated, the same token refreshes.
    app(Organizations::class)->reactivate($org->id, 'sub_operator');

    $response = $this->postJson('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $app->client_id, 'refresh_token' => $refresh])
        ->assertOk();
    $claims = app(TokenSigner::class)->verify((string) $response->json('access_token'), [SigningAlg::RS256])->all();

    expect($claims['org'] ?? null)->toBe($org->id);
})->with(['suspended', 'archived']);

it('mints no client_credentials token for a closed organization\'s own app, and its earlier token stops reaching the vault (#28)', function (string $how): void {
    $org = cocOrg();
    $registered = app(ClientRegistry::class)->register(new NewClient(
        'Acme backend',
        grantTypes: ['client_credentials'],
        scopes: ['vault.manage', 'vault.lease'],
        organizationId: $org->id,
    ));
    $basic = ['Authorization' => 'Basic '.base64_encode($registered->client->client_id.':'.$registered->secret)];

    $before = (string) $this->postJson('/oauth/token', ['grant_type' => 'client_credentials', 'scope' => 'vault.manage vault.lease'], $basic)
        ->assertOk()->json('access_token');
    $this->withToken($before)->postJson('/api/v1/vault/secrets', ['name' => 'openai', 'provider' => 'openai', 'secret' => 'sk-dummy-value'])
        ->assertSuccessful();
    $this->flushHeaders();

    cocClose($org, $how);

    $this->postJson('/oauth/token', ['grant_type' => 'client_credentials', 'scope' => 'vault.manage vault.lease'], $basic)
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    $this->withToken($before)->postJson('/api/v1/vault/secrets', ['name' => 'more', 'provider' => 'openai', 'secret' => 'sk-dummy-2'])
        ->assertUnauthorized();
    $this->flushHeaders();

    // Reactivated: the backend is back, with the token it already held and new ones.
    app(Organizations::class)->reactivate($org->id, 'sub_operator');

    $this->withToken($before)->postJson('/api/v1/vault/secrets', ['name' => 'again', 'provider' => 'openai', 'secret' => 'sk-dummy-3'])
        ->assertSuccessful();
    $this->flushHeaders();
    $this->postJson('/oauth/token', ['grant_type' => 'client_credentials', 'scope' => 'vault.manage vault.lease'], $basic)->assertOk();
})->with(['suspended', 'archived']);

it('refuses an org-bound access token at introspection while the organization is closed (#27)', function (): void {
    $org = cocOrg();
    $person = app(Subjects::class)->create('ada@acme.test', 'Ada', 'a-strong-unbreached-passphrase');
    app(Memberships::class)->add($org->id, $person->id, MembershipRole::Member);
    $app = cocPublicApp('Ledger');
    $access = app(TokenIssuer::class)->issueForUser($app, $person->id, $org->id, ['openid'])->token;

    $this->withToken($access)->getJson('/oauth/userinfo')->assertOk();
    $this->flushHeaders();

    cocClose($org, 'suspended');

    $this->withToken($access)->getJson('/oauth/userinfo')->assertUnauthorized();
});

it('stops a closed organization\'s SCIM directory from authenticating, and lets it back in on reactivation (#23)', function (string $how): void {
    $org = cocOrg();
    $directory = app(Directories::class)->register($org->id, 'Okta');

    $this->withToken($directory->token)->getJson('/scim/v2/Users')->assertOk();
    $this->flushHeaders();

    cocClose($org, $how);

    $this->withToken($directory->token)->getJson('/scim/v2/Users')->assertUnauthorized();
    $this->withToken($directory->token)->postJson('/scim/v2/Users', [
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'userName' => 'new@acme.test',
        'emails' => [['value' => 'new@acme.test', 'primary' => true]],
        'active' => true,
    ])->assertUnauthorized();
    $this->flushHeaders();

    expect(app(Subjects::class)->findByEmail('new@acme.test'))->toBeNull();

    app(Organizations::class)->reactivate($org->id, 'sub_operator');

    $this->withToken($directory->token)->getJson('/scim/v2/Users')->assertOk();
})->with(['suspended', 'archived']);
