<?php

declare(strict_types=1);

use App\Platform\Console\WebhookEventCatalogue;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Webhooks\Models\WebhookEndpoint;

/*
 * A billing or CRM backend keeps its customers and the environment's organizations one to
 * one with its own client-credentials token: it reads organizations, creates one and invites its
 * people, and keeps the webhook it receives changes on — and nothing else, with nobody approving
 * each write.
 */

/** @param  list<string>  $scopes */
function provisioningToken(array $scopes = ['organizations:read', 'organizations:write', 'invitations:write', 'webhooks:read', 'webhooks:write']): string
{
    $client = app(ClientRegistry::class)->register(new NewClient(name: 'Billing', grantTypes: ['authorization_code', 'client_credentials'], scopes: ['openid', ...$scopes], redirectUris: ['https://billing.example/auth/callback']))->client;

    return app(TokenIssuer::class)->issueClientCredentials($client, $scopes)->token;
}

it('lets an app\'s own token create an organization with an owner, invite to it and read it back', function (): void {
    $token = provisioningToken();
    $owner = app(Subjects::class)->create('owner@provisioning.example', 'Owner')->id;

    $created = $this->withToken($token)->postJson('/api/v1/organizations', ['name' => 'Hubhus', 'owner_user_id' => $owner])->assertCreated();
    $hubhus = Organization::query()->where('name', 'Hubhus')->sole();
    expect(app(Memberships::class)->activeRole((string) $hubhus->getKey(), $owner))->toBe(MembershipRole::Owner);

    $this->withToken($token)->postJson('/api/v1/organizations/'.$hubhus->getKey().'/invitations', ['email' => 'books@hubhus.example', 'role' => 'admin'])->assertCreated();

    $this->withToken($token)->getJson('/api/v1/organizations')->assertOk()->assertJsonFragment(['name' => 'Hubhus']);
});

it('refuses an app\'s token every other write under the same scopes', function (): void {
    $token = provisioningToken();
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'))->id;

    $this->withToken($token)->deleteJson("/api/v1/organizations/{$org}")->assertForbidden();
    $this->withToken($token)->patchJson("/api/v1/organizations/{$org}", ['name' => 'Renamed'])->assertForbidden();
    $this->withToken($token)->postJson("/api/v1/organizations/{$org}/suspend")->assertForbidden();

    expect(Organization::query()->whereKey($org)->value('name'))->toBe('Acme');
});

it('refuses an app\'s token without the scope', function (): void {
    $this->withToken(provisioningToken(['organizations:read']))->postJson('/api/v1/organizations', ['name' => 'Nope'])->assertForbidden();
    $this->withToken(provisioningToken(['apps.manifest']))->getJson('/api/v1/organizations')->assertForbidden();

    expect(Organization::query()->where('name', 'Nope')->exists())->toBeFalse();
});

it('keeps the scopes out of reach of a key that can only register apps', function (): void {
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'App registrar', ['apps:write'])->plaintext;

    $this->withToken($key)->postJson('/api/v1/apps', [
        'name' => 'Sneaky', 'type' => 'machine', 'scopes' => ['organizations:write'],
    ])->assertStatus(422);
});

it('lets an app\'s own token keep a webhook on its own origin, and only there', function (): void {
    $token = provisioningToken();
    $event = WebhookEventCatalogue::offered()[0];

    // Somebody else's endpoint, registered by a key: the app's token never sees or moves it.
    $key = app(EnvironmentApiKeys::class)->issue('env_test', 'Ops', ['webhooks:write'])->plaintext;
    $theirs = $this->withToken($key)->postJson('/api/v1/webhooks', ['url' => 'https://ops.example/in', 'event_types' => [$event], 'environment_wide' => true])->assertCreated()->json('data.id');

    $this->withToken($token)->postJson('/api/v1/webhooks', ['url' => 'https://elsewhere.example/in', 'event_types' => [$event], 'environment_wide' => true])->assertForbidden();

    $mine = $this->withToken($token)->postJson('/api/v1/webhooks', ['url' => 'https://billing.example/webhooks/cbox-id/abc', 'event_types' => [$event], 'environment_wide' => true, 'signature_scheme' => 'standard_webhooks'])
        ->assertCreated()->assertJsonPath('data.secret', fn (mixed $secret): bool => is_string($secret) && str_starts_with($secret, 'whsec_'))->json('data.id');

    expect(array_column($this->withToken($token)->getJson('/api/v1/webhooks')->assertOk()->json('data'), 'id'))->toBe([$mine]);

    $this->withToken($token)->patchJson("/api/v1/webhooks/{$mine}", ['url' => 'https://elsewhere.example/in'])->assertForbidden();
    $this->withToken($token)->patchJson("/api/v1/webhooks/{$mine}", ['url' => 'https://billing.example/webhooks/cbox-id/def'])->assertOk();
    $this->withToken($token)->patchJson("/api/v1/webhooks/{$theirs}", ['url' => 'https://billing.example/in'])->assertNotFound();
    $this->withToken($token)->deleteJson("/api/v1/webhooks/{$theirs}")->assertNotFound();

    expect(WebhookEndpoint::query()->whereKey($theirs)->value('url'))->toBe('https://ops.example/in')
        ->and(WebhookEndpoint::query()->whereKey($mine)->value('url'))->toBe('https://billing.example/webhooks/cbox-id/def');

    $this->withToken($token)->deleteJson("/api/v1/webhooks/{$mine}")->assertSuccessful();
});
