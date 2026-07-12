<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Volt\Volt;

/**
 * Populate CurrentUser as the Authenticate middleware would, then drive the
 * component directly.
 *
 * @return array{0: string, 1: Organization}
 */
function actingAsConsentUser(): array
{
    $subject = app(Subjects::class)->create('member@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-consent'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');

    return [$subject->id, $org];
}

/**
 * Register an OAuth client and return its public client_id.
 *
 * @param  list<string>  $redirectUris
 */
function registerConsentClient(string $orgId, array $redirectUris = ['https://app.test/cb']): string
{
    $registered = app(ClientRegistry::class)->register(
        new NewClient('App', redirectUris: $redirectUris, organizationId: $orgId)
    );

    return $registered->client->client_id;
}

it('renders an error state for an unknown client', function () {
    [, $org] = actingAsConsentUser();
    registerConsentClient($org->id);

    Volt::test('oauth.consent', [
        'client_id' => 'does-not-exist',
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])
        ->assertNoRedirect()
        ->assertSee('Authorization failed');
});

it('rejects a redirect_uri not registered to the client', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://evil.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])
        ->assertNoRedirect()
        ->assertSee('Authorization failed');
});

it('issues a code and redirects on approve for a valid request', function () {
    [, $org] = actingAsConsentUser();
    $clientId = registerConsentClient($org->id);

    $component = Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])
        ->assertSet('error', null)
        ->assertSee('Authorize App')
        ->call('approve');

    $redirect = $component->effects['redirect'] ?? null;

    expect($redirect)->not->toBeNull()
        ->and($redirect)->toStartWith('https://app.test/cb?')
        ->and($redirect)->toContain('state=xyz')
        ->and($redirect)->toMatch('/[?&]code=/');
});
