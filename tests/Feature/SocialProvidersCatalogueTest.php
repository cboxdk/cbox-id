<?php

declare(strict_types=1);

use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Enums\TokenEndpointAuthMethod;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

/**
 * LinkedIn, Bitbucket, Xero and Intuit — the catalogue entries laravel-id 1.23 added — on
 * the console's Social login page and on a sign-in page, and the discovery that makes the
 * OpenID ones work: read where the catalogue says (Intuit publishes its document away from
 * its issuer) and stored whole, so the UserInfo endpoint and the token endpoint auth method
 * the provider's document names reach the client.
 */
it('offers the four new providers in the console catalogue', function (): void {
    actingAsRole(MembershipRole::Owner);

    $available = collect((array) catalogueSocialPage()['available']);

    expect($available->pluck('key')->all())->toContain('linkedin', 'bitbucket', 'xero', 'intuit')
        ->and($available->firstWhere('key', 'bitbucket')['protocol'])->toBe('OAuth 2.0')
        ->and($available->firstWhere('key', 'intuit')['protocol'])->toBe('OpenID Connect');
});

it('discovers Intuit at its published document, not under its issuer, and keeps the UserInfo endpoint', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    Http::fake(['developer.api.intuit.com/*' => Http::response([
        'issuer' => 'https://oauth.platform.intuit.com/op/v1',
        'authorization_endpoint' => 'https://appcenter.intuit.com/connect/oauth2',
        'token_endpoint' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        'userinfo_endpoint' => 'https://accounts.platform.intuit.com/v1/openid_connect/userinfo',
        'jwks_uri' => 'https://oauth.platform.intuit.com/op/v1/jwks',
        'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
    ])]);

    enableSocialProvider(['provider' => 'intuit', 'clientId' => 'intuit-client', 'clientSecret' => 'intuit-secret'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://developer.api.intuit.com/.well-known/openid_configuration');
    Http::assertNotSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://oauth.platform.intuit.com/op/v1/.well-known'));

    [$connection] = app(Connections::class)->catalogueProvidersFor($org->id);
    $config = app(Connections::class)->oidcConfig($connection);

    expect($connection->type)->toBe(ConnectionType::Oidc)
        ->and($config->issuer)->toBe('https://oauth.platform.intuit.com/op/v1')
        ->and($config->userinfoEndpoint)->toBe('https://accounts.platform.intuit.com/v1/openid_connect/userinfo')
        ->and($config->tokenEndpointAuthMethod)->toBe(TokenEndpointAuthMethod::ClientSecretBasic)
        ->and($config->clientSecret)->toBe('intuit-secret');
});

it('discovers Xero under its issuer, keeping the body form when the document allows it', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    Http::fake(['identity.xero.com/*' => Http::response([
        'issuer' => 'https://identity.xero.com',
        'authorization_endpoint' => 'https://login.xero.com/identity/connect/authorize',
        'token_endpoint' => 'https://identity.xero.com/connect/token',
        'userinfo_endpoint' => 'https://identity.xero.com/connect/userinfo',
        'jwks_uri' => 'https://identity.xero.com/.well-known/openid-configuration/jwks',
        'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
    ])]);

    enableSocialProvider(['provider' => 'xero', 'clientId' => 'xero-client', 'clientSecret' => 'xero-secret'])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://identity.xero.com/.well-known/openid-configuration');

    [$connection] = app(Connections::class)->catalogueProvidersFor($org->id);

    expect(app(Connections::class)->oidcConfig($connection)->tokenEndpointAuthMethod)->toBe(TokenEndpointAuthMethod::ClientSecretPost);
});

it('enables Bitbucket over OAuth 2.0 and shows it on the sign-in page', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    enableSocialProvider(['provider' => 'bitbucket'])->assertSessionHasNoErrors();

    [$connection] = app(Connections::class)->catalogueProvidersFor($org->id);

    expect($connection->type)->toBe(ConnectionType::OAuth2)
        ->and($connection->name)->toBe('Bitbucket');

    signOutOfConsole();

    $this->get(route('login.branded', $org->slug))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/login')
            ->where('providers', fn (Collection $providers): bool => $providers
                ->contains(fn (array $provider): bool => $provider['provider'] === 'bitbucket' && $provider['label'] === 'Bitbucket')));
});

/** The console's Social login page, as props. */
function catalogueSocialPage(): array
{
    $props = [];

    test()->get(route('social-providers'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

    return $props;
}
