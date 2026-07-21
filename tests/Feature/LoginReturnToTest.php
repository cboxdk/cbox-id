<?php

declare(strict_types=1);

use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('bounces an unauthenticated request to login and remembers where it was headed', function (): void {
    // A user sent to a protected route (e.g. mid /oauth/authorize) is returned to
    // login, but the intended URL is stashed so login can resume it afterwards.
    $this->get('/dashboard')->assertRedirect(route('login'));

    expect(session()->get('url.intended'))->toContain('/dashboard');
});

it('preserves an authorize request as the intended url', function (): void {
    // A REAL client: /oauth/authorize now validates the client and its redirect_uri
    // BEFORE deciding what to do about authentication, because RFC 6749 §4.1.2.1 needs a
    // verified redirect_uri to answer the client at all. With a fabricated client_id this
    // correctly renders the unknown-client page instead — which is what it should always
    // have done; the old fixture only reached the login redirect because the auth
    // middleware ran first and the client was never checked.
    $registered = app(ClientRegistry::class)->register(new NewClient(
        'Test app',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ));

    $query = http_build_query([
        'client_id' => $registered->client->client_id,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'code_challenge' => 'xyz',
        'code_challenge_method' => 'S256',
    ]);

    $this->get('/oauth/authorize?'.$query)->assertRedirect(route('login'));

    expect(session()->get('url.intended'))->toContain('/oauth/authorize')
        ->and(session()->get('url.intended'))->toContain($registered->client->client_id);
});

/**
 * The silent-renew path. An SPA loads this in a hidden iframe and waits for a
 * postMessage from the callback; redirecting to /login framed the sign-in page (or
 * X-Frame-Options blocked it), the promise never resolved, and the SPA signed the user
 * out on every token refresh. OIDC Core §3.1.2.6 wants the answer sent to the client.
 */
it('answers an unauthenticated prompt=none with login_required at the redirect_uri', function (): void {
    $registered = app(ClientRegistry::class)->register(new NewClient(
        'SPA',
        ClientType::Public,
        redirectUris: ['https://app.test/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
    ));

    $query = http_build_query([
        'client_id' => $registered->client->client_id,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'code_challenge' => 'xyz',
        'code_challenge_method' => 'S256',
        'state' => 'st-123',
        'prompt' => 'none',
    ]);

    $location = $this->get('/oauth/authorize?'.$query)->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://app.test/cb?');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $params);

    expect($params['error'])->toBe('login_required')
        // §4.1.2.1: state MUST be echoed so the client can correlate the failure.
        ->and($params['state'])->toBe('st-123')
        ->and($params)->toHaveKey('iss');
});
