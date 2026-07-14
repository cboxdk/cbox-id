<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

it('locks validated request parameters so the browser cannot tamper with them between requests', function () {
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
    ])->assertSet('error', null);

    // #[Locked]: a redirect_uri (or scopes) validated in mount() cannot be mutated
    // by a crafted Livewire update — the open-redirect / code-exfiltration vector.
    expect(fn () => $component->set('redirectUri', 'https://evil.test/cb'))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect(fn () => $component->set('scopes', ['openid', 'admin']))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses to mint a code at approval if the client/redirect is no longer valid', function () {
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
    ])->assertSet('error', null);

    // Client deregistered between render and approval — approve() re-asserts the
    // invariant instead of trusting mount(), so no code is issued.
    Client::query()->where('client_id', $clientId)->delete();

    $component->call('approve');

    expect($component->effects['redirect'] ?? null)->toBeNull()
        ->and($component->get('error'))->not->toBeNull();
});

it('refuses to mint a code for a suspended organization', function () {
    $subject = app(Subjects::class)->create('susp-consent@acme.test', 'Member', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-susp-consent'));
    app(Memberships::class)->add($org->id, $subject->id, 'owner');
    $org->update(['status' => OrganizationStatus::Suspended]);
    $org->refresh();
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, 'owner');

    $clientId = registerConsentClient($org->id);

    $component = Volt::test('oauth.consent', [
        'client_id' => $clientId,
        'redirect_uri' => 'https://app.test/cb',
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => 'abc',
        'code_challenge_method' => 'S256',
    ])->assertSet('error', null);

    $component->call('approve');

    expect($component->effects['redirect'] ?? null)->toBeNull()
        ->and($component->get('error'))->not->toBeNull();
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
