<?php

declare(strict_types=1);

use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Kernel\Crypto\SecretPrefix;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * laravel-id 2.5 mints every bearer secret as `cbid_<kind>_`. What ID mints itself must
 * follow, and what was minted before (`csec_`, `scim_`) must keep working: every secret is
 * found by the hash of the whole plaintext, never by its prefix.
 */

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

it('rotates a SCIM directory token to cbid_scim_, and the old scim_ token stops working', function (): void {
    crudSetup();
    $orgId = app(Organizations::class)->create(new NewOrganization(name: 'Prefix', slug: 'prefix-org'))->id;
    grantFeature($orgId, 'cbox-id-scim');
    $directory = app(Directories::class)->register($orgId, 'HR')->directory;

    // A token the previous release minted, as the customer's IdP holds it today.
    $legacy = SecretPrefix::ScimToken->legacy().bin2hex(random_bytes(32));
    Directory::query()->whereKey($directory->id)->update(['bearer_token_hash' => hash('sha256', $legacy)]);
    expect(app(Directories::class)->authenticate($legacy)?->id)->toBe($directory->id);

    confirmEnvironmentStepUp();
    $url = route('environment.directories.show', $directory->id);
    $this->from($url)->post(route('environment.directories.rotate', $directory->id))->assertRedirect($url);

    $token = flashed('newToken');

    expect($token)->toBeString()->toStartWith(SecretPrefix::ScimToken->value)
        ->and(app(Directories::class)->authenticate($token)?->id)->toBe($directory->id)
        ->and(app(Directories::class)->authenticate($legacy))->toBeNull();
})->group('security');

it('mints new client secrets as cbid_cs_ and still exchanges a csec_ one at the token endpoint', function (): void {
    $registered = app(ClientRegistry::class)->register(new NewClient(
        name: 'Billing sync',
        type: ClientType::Confidential,
        grantTypes: ['client_credentials'],
        scopes: ['id:users:read'],
    ));

    expect((string) $registered->secret)->toStartWith(SecretPrefix::ClientSecret->value);

    // The secret an app holds in CBOX_ID_CLIENT_SECRET from before the switch.
    $legacy = SecretPrefix::ClientSecret->legacy().bin2hex(random_bytes(32));
    DB::table('oauth_client_secrets')->where('oauth_client_id', $registered->client->id)->update(['secret_hash' => hash('sha256', $legacy)]);

    $this->withBasicAuth($registered->client->client_id, $legacy)
        ->postJson('/oauth/token', ['grant_type' => 'client_credentials'])
        ->assertOk()
        ->assertJsonStructure(['access_token']);

    $this->withBasicAuth($registered->client->client_id, $legacy.'x')
        ->postJson('/oauth/token', ['grant_type' => 'client_credentials'])
        ->assertUnauthorized();
})->group('security');
