<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Enums\GrantType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Support\ClientAudit;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Enums\MembershipRole;

/*
|--------------------------------------------------------------------------
| An app's settings: token lifetime, token exchange, back-channel logout, key prefix
|--------------------------------------------------------------------------
| Four settings the registry has always been able to hold and the console could not set.
| Each is its own form and its own write, so each change is one entry on the trail naming
| exactly what changed — and a refusal is said on the field that asked, in the page's words.
*/

beforeEach(function (): void {
    installedDeployment();
});

function settingsApp(string $organizationId, ClientType $type = ClientType::Confidential): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: 'Billing',
        type: $type,
        redirectUris: ['https://billing.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $organizationId,
    ))->client;
}

/** @return list<string> the fields each `app.updated` entry changed, oldest first */
function settingsChanges(Client $client): array
{
    return AuditEntry::query()
        ->where('action', ClientAudit::UPDATED)
        ->where('target_id', $client->client_id)
        ->orderBy('sequence')
        ->get()
        ->map(fn (AuditEntry $entry): string => implode(',', array_keys((array) ($entry->context['changes'] ?? []))))
        ->all();
}

it('sets an app\'s access token lifetime within the install\'s ceiling, and states the ceiling', function (): void {
    config(['cbox-id.oauth.max_access_token_ttl' => 7200, 'cbox-id.oauth.access_token_ttl' => 900]);

    [, $org] = actingAsRole(MembershipRole::Owner);
    $client = settingsApp($org->id);

    $lifetime = (array) test()->get(route('clients.settings', $client->id))->assertOk()->inertiaProps('lifetime');

    expect($lifetime)->toMatchArray(['minutes' => null, 'defaultMinutes' => 15, 'ceilingMinutes' => 120]);

    test()->from(route('clients.settings', $client->id))
        ->put(route('clients.settings.lifetime', $client->id), ['minutes' => 30])
        ->assertSessionHasNoErrors();

    expect($client->refresh()->access_token_ttl)->toBe(1800);

    test()->from(route('clients.settings', $client->id))
        ->put(route('clients.settings.lifetime', $client->id), ['minutes' => 121])
        ->assertSessionHasErrors(['minutes' => "This install lets an app's access tokens live at most 120 minutes."]);

    // Empty returns it to the default.
    test()->from(route('clients.settings', $client->id))
        ->put(route('clients.settings.lifetime', $client->id), ['minutes' => ''])
        ->assertSessionHasNoErrors();

    expect($client->refresh()->access_token_ttl)->toBeNull()
        ->and(settingsChanges($client))->toBe(['access_token_ttl', 'access_token_ttl']);
});

it('turns token exchange on and off for an app that can prove who it is, and only for one', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $client = settingsApp($org->id);

    test()->from(route('clients.settings', $client->id))
        ->put(route('clients.settings.exchange', $client->id), ['enabled' => true])
        ->assertSessionHasNoErrors();

    expect($client->refresh()->grant_types)->toContain(GrantType::TokenExchange->value, 'authorization_code');

    test()->from(route('clients.settings', $client->id))
        ->put(route('clients.settings.exchange', $client->id), ['enabled' => false])
        ->assertSessionHasNoErrors();

    expect($client->refresh()->grant_types)->toBe(['authorization_code'])
        ->and(settingsChanges($client))->toBe(['grant_types', 'grant_types']);

    $public = settingsApp($org->id, ClientType::Public);

    expect((array) test()->get(route('clients.settings', $public->id))->inertiaProps('exchange'))
        ->toMatchArray(['available' => false]);

    test()->from(route('clients.settings', $public->id))
        ->put(route('clients.settings.exchange', $public->id), ['enabled' => true])
        ->assertSessionHasErrors(['enabled' => 'Only an app that holds a secret or its own keys can exchange tokens. A public app cannot prove who is asking.']);

    expect($public->refresh()->grant_types)->toBe(['authorization_code']);
});

it('saves where the app is told somebody signed out, and refuses an address it will not call', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $client = settingsApp($org->id);

    test()->from(route('clients.settings', $client->id))
        ->put(route('clients.settings.logout', $client->id), ['uri' => 'https://billing.example/logout', 'sessionRequired' => true])
        ->assertSessionHasNoErrors();

    expect($client->refresh()->backchannel_logout_uri)->toBe('https://billing.example/logout')
        ->and($client->backchannel_logout_session_required)->toBeTrue();

    test()->from(route('clients.settings', $client->id))
        ->put(route('clients.settings.logout', $client->id), ['uri' => 'http://billing.example/logout', 'sessionRequired' => false])
        ->assertSessionHasErrors(['uri' => 'The logout URI must use https (or http on localhost): http://billing.example/logout']);

    expect($client->refresh()->backchannel_logout_uri)->toBe('https://billing.example/logout');

    // Cleared, the session requirement goes with it.
    test()->from(route('clients.settings', $client->id))
        ->put(route('clients.settings.logout', $client->id), ['uri' => '', 'sessionRequired' => true])
        ->assertSessionHasNoErrors();

    expect($client->refresh()->backchannel_logout_uri)->toBeNull()
        ->and($client->backchannel_logout_session_required)->toBeFalse();
});

it('declares an API key prefix, and refuses a malformed, reserved or taken one', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $client = settingsApp($org->id);
    $other = settingsApp($org->id);

    $put = fn (Client $app, string $prefix) => test()->from(route('clients.settings', $app->id))
        ->put(route('clients.settings.api-keys', $app->id), ['prefix' => $prefix]);

    $put($client, 'Acme-Live')
        ->assertSessionHasErrors(['prefix' => 'Use 2 to 16 lowercase letters or digits, starting with a letter, then _live or _test — for example acme_live.']);

    $put($client, 'cbid_live')
        ->assertSessionHasErrors(['prefix' => 'That prefix is reserved for Cbox ID\'s own keys. Choose another.']);

    $put($client, 'acme_live')->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'API keys turned on for this app. New keys start with acme_live_.');

    expect($client->refresh()->api_key_prefix)->toBe('acme_live');

    $put($other, 'acme_live')
        ->assertSessionHasErrors(['prefix' => 'Another app in this environment already uses this prefix. Choose another.']);

    expect($other->refresh()->api_key_prefix)->toBeNull();

    $put($client, '')->assertSessionHasNoErrors();

    expect($client->refresh()->api_key_prefix)->toBeNull()
        ->and(settingsChanges($client))->toBe(['api_key_prefix', 'api_key_prefix']);
});

it('keeps the settings page and its writes to whoever manages the app', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    $platform = app(ClientRegistry::class)->register(new NewClient(
        name: 'Platform app',
        type: ClientType::Confidential,
        redirectUris: ['https://platform.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        firstParty: true,
    ))->client;

    test()->get(route('clients.settings', $platform->id))->assertForbidden();
    test()->put(route('clients.settings.lifetime', $platform->id), ['minutes' => 1440])->assertForbidden();
    test()->put(route('clients.settings.exchange', $platform->id), ['enabled' => true])->assertForbidden();
    test()->put(route('clients.settings.api-keys', $platform->id), ['prefix' => 'squat_live'])->assertForbidden();

    $platform->refresh();

    expect($platform->access_token_ttl)->toBeNull()
        ->and($platform->grant_types)->toBe(['authorization_code'])
        ->and($platform->api_key_prefix)->toBeNull()
        ->and($org->id)->not->toBe('');
})->group('security');
