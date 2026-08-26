<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\PushedAuthorizationRequests;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;

function fapiUserAndClient(): array
{
    $subject = app(Subjects::class)->create('fapi@acme.test', 'FAPI', 'super-secret-1234');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-fapi'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    // The request session too: these drive real requests now, and one arriving without
    // it is bounced to sign-in — where every refusal below is true for the wrong reason.
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

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
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        'response_type' => 'code',
        'code_challenge_method' => 'S256',
    ];
}

it('returns the issuer in the authorization response (RFC 9207)', function () {
    config(['cbox-id.issuer' => 'https://id.acme.test']);
    [, $client] = fapiUserAndClient();

    $target = leftFor(answerConsent(consentScreen(authorizeParams($client->client_id))));

    expect($target)->toContain('iss=https%3A%2F%2Fid.acme.test');
});

it('refuses a non-PAR authorization request when PAR is required (FAPI)', function () {
    config(['cbox-id.oauth.require_par' => true]);
    [, $client] = fapiUserAndClient();

    $response = authorizeRequest(authorizeParams($client->client_id))->assertOk();

    expect(consentRefusal($response))->toContain('requires pushed authorization requests');
});

it('accepts a pushed request when PAR is required (FAPI)', function () {
    config(['cbox-id.oauth.require_par' => true]);
    [, $client] = fapiUserAndClient();

    $pushed = app(PushedAuthorizationRequests::class)->push($client, authorizeParams($client->client_id));

    $target = leftFor(answerConsent(consentScreen([
        'client_id' => $client->client_id,
        'request_uri' => $pushed['request_uri'],
    ])));

    expect($target)->toStartWith('https://app.test/cb?');
});
