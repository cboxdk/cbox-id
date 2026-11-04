<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\TokenIssuer;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\ValueObjects\KeyProvenance;

it('manages namespace subsets through authenticated management actions', function (): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));
    $client = app(ClientRegistry::class)->register(new NewClient('Preferences app', ClientType::Confidential, scopes: ['user_properties:read', 'user_properties:write']));
    $key = app(EnvironmentApiKeys::class)->issue($tenant['environment']->id, 'Namespace administrator', ['user_property_namespaces:read', 'user_property_namespaces:write'], null, new KeyProvenance);
    $url = '/api/v1/user-property-namespaces/team';
    $this->withToken($key->plaintext)->putJson($url, [
        'readers' => [$client->client->client_id],
        'writers' => [$client->client->client_id],
    ])->assertOk()->assertJsonPath('data.name', 'team')->assertJsonPath('data.readers.0', $client->client->client_id);
    $this->withToken($key->plaintext)->getJson($url)->assertOk()->assertJsonPath('data.writers.0', $client->client->client_id);
    $user = app(Subjects::class)->create('properties@example.test');
    $token = app(TokenIssuer::class)->issueClientCredentials($client->client, ['user_properties:read', 'user_properties:write']);
    $propertiesUrl = '/oauth/users/'.$user->id.'/properties/team';
    $this->withToken($token->token)->patchJson($propertiesUrl, ['version' => 0, 'set' => ['custom' => ['enabled' => true]]])
        ->assertOk()->assertJsonPath('data.custom.enabled', true);
    $this->withToken($token->token)->getJson($propertiesUrl)->assertOk()->assertJsonPath('version', 1);

    $this->withToken($key->plaintext)->putJson($url, [])->assertOk()->assertJsonPath('data.readers', []);
    $this->withToken($token->token)->getJson($propertiesUrl)->assertForbidden();
    $limited = app(EnvironmentApiKeys::class)->issue($tenant['environment']->id, 'User editor', ['users:write'], null, new KeyProvenance);
    $this->withToken($limited->plaintext)->putJson($url, ['readers' => [$client->client->client_id]])->assertForbidden();
});
