<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

function fapiUserAndClient(): array
{
    $subject = app(Subjects::class)->create('fapi@acme.test', 'FAPI', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-fapi'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');

    $client = app(ClientRegistry::class)->register(
        new NewClient('App', redirectUris: ['https://app.test/cb'], organizationId: $org->id)
    )->client;

    return [$subject->id, $client];
}

/**
 * @return array<string, string>
 */
function authorizeParams(string $clientId): array
{
    return [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        'code_challenge_method' => 'S256',
    ];
}

it('returns the issuer in the authorization response (RFC 9207)', function () {
    config(['cbox-id.issuer' => 'https://id.acme.test']);
    [, $client] = fapiUserAndClient();

    $component = Volt::test('oauth.consent', authorizeParams($client->client_id))
        ->assertSet('error', null)
        ->call('approve');

    expect($component->effects['redirect'] ?? '')->toContain('iss=https%3A%2F%2Fid.acme.test');
});

it('refuses a non-PAR authorization request when PAR is required (FAPI)', function () {
    config(['cbox-id.oauth.require_par' => true]);
    [, $client] = fapiUserAndClient();

    Volt::test('oauth.consent', authorizeParams($client->client_id))
        ->assertNoRedirect()
        ->assertSee('requires pushed authorization requests');
});

it('accepts a pushed request when PAR is required (FAPI)', function () {
    config(['cbox-id.oauth.require_par' => true]);
    [, $client] = fapiUserAndClient();

    $pushed = app(PushedAuthorizationRequests::class)->push($client, authorizeParams($client->client_id));

    $component = Volt::test('oauth.consent', [
        'client_id' => $client->client_id,
        'request_uri' => $pushed['request_uri'],
    ])
        ->assertSet('error', null)
        ->call('approve');

    expect($component->effects['redirect'] ?? '')->toStartWith('https://app.test/cb?');
});
