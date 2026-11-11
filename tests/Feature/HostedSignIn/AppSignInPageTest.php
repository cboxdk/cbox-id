<?php

declare(strict_types=1);

use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntryData;
use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Inertia\Testing\AssertableInertia;

/**
 * The hosted sign-in is the front door of every app that signs in here: it says which app
 * the person is on their way to, takes the address the app named, and a page that cannot
 * finish a request offers the way back to that app.
 */
function hostedApp(string $name = 'Cbox Billing', array $redirectUris = ['https://billing.example.com/auth/callback'], bool $firstParty = true): string
{
    return app(ClientRegistry::class)->register(new NewClient(
        $name,
        ClientType::Public,
        redirectUris: $redirectUris,
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'email'],
        firstParty: $firstParty,
    ))->client->client_id;
}

/** A signed-in member of Acme: the session row, the browser's session and the request's user. */
function hostedMember(string $email = 'pat@acme.test', array $amr = ['pwd']): string
{
    $subject = app(Subjects::class)->create($email, 'Pat', 'supersecret123');
    $org = app(Organizations::class)->bySlug('acme') ?? app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    $session = app(SessionManager::class)->start($subject->id, $org->id, $amr);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    return $subject->id;
}

/** @return array<string, string> */
function hostedAppRequest(string $clientId, array $extra = []): array
{
    return ['client_id' => $clientId, 'redirect_uri' => 'https://billing.example.com/auth/callback', ...$extra];
}

beforeEach(fn () => installedDeployment());

it('names the app on the sign-in page when an authorization sent the person there', function (): void {
    $clientId = hostedApp();

    authorizeRequest(hostedAppRequest($clientId))->assertRedirect(route('login'));

    $this->get(route('login'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/login')
        ->where('purpose', 'Sign in to continue to Cbox Billing.')
        ->where('signingInTo.name', 'Cbox Billing')
        ->where('signingInTo.iconUrl', null));
});

it('says nothing about an identity console to somebody who came by themselves', function (): void {
    $this->get(route('login'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('purpose', 'Welcome back. Sign in to continue.')
        ->where('signingInTo', null));
});

it('does not take the app from the address of the sign-in page', function (): void {
    $clientId = hostedApp();

    $this->get(route('login', ['app' => $clientId, 'client_id' => $clientId]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('signingInTo', null));
});

it('stops naming the app once the browser is headed somewhere else', function (): void {
    $clientId = hostedApp();

    authorizeRequest(hostedAppRequest($clientId))->assertRedirect(route('login'));
    session()->put('url.intended', url('/device'));

    $this->get(route('login'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('signingInTo', null));
});

it('draws the app\'s catalog icon and nothing for an app the catalog does not list', function (): void {
    $clientId = hostedApp();

    app(AppCatalog::class)->save('billing', CatalogEntryData::fromArray([
        'version' => 0, 'name' => 'Cbox Billing', 'launch_url' => 'https://billing.example.com/launch',
        'enabled' => true, 'position' => 1, 'audience' => 'everyone', 'client_ids' => [$clientId],
        'icon' => pngDataUri(),
    ]));

    authorizeRequest(hostedAppRequest($clientId))->assertRedirect(route('login'));

    $this->get(route('login'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('signingInTo.iconUrl', fn (mixed $url): bool => is_string($url) && str_contains($url, '/app-icons/')));
});

it('fills in the address the app named with login_hint and submits nothing', function (): void {
    $clientId = hostedApp();

    authorizeRequest(hostedAppRequest($clientId, ['login_hint' => 'pat@acme.test']))->assertRedirect(route('login'));

    $this->get(route('login'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('email', 'pat@acme.test'));

    // Filled in, not acted on: nobody was identified and no step was taken.
    expect(flashed('identified'))->toBeNull();

    // The resumed request still carries it, so a second pass through sign-in keeps it.
    expect((string) session('url.intended'))->toContain('login_hint=pat%40acme.test');
});

it('opens the account chooser when login_hint names somebody other than the signed-in person', function (): void {
    $clientId = hostedApp();
    hostedMember('pat@acme.test');

    // Another address: not continued as Pat. The chooser, with the request kept.
    authorizeRequest(hostedAppRequest($clientId, ['login_hint' => 'sam@acme.test']))->assertRedirect(route('accounts'));

    $resume = (string) session('url.intended');

    expect($resume)->toContain('login_hint=sam%40acme.test')->toContain('reauthed=1');

    // Having chosen (here: stayed as Pat), the request goes on. A hint is a hint.
    $this->get($resume)->assertRedirect();
    expect((string) $this->get($resume)->headers->get('Location'))->toStartWith('https://billing.example.com/auth/callback?code=');
});

it('continues when login_hint names the signed-in person, whatever the case', function (): void {
    $clientId = hostedApp();
    hostedMember('pat@acme.test');

    $location = (string) authorizeRequest(hostedAppRequest($clientId, ['login_hint' => 'Pat@Acme.test']))->headers->get('Location');

    expect($location)->toStartWith('https://billing.example.com/auth/callback?code=');
});

it('answers login_required to a silent request whose login_hint names somebody else', function (): void {
    $clientId = hostedApp();
    hostedMember('pat@acme.test');

    $location = (string) authorizeRequest(hostedAppRequest($clientId, ['login_hint' => 'sam@acme.test', 'prompt' => 'none']))->headers->get('Location');

    expect($location)->toStartWith('https://billing.example.com/auth/callback?')
        ->toContain('error=login_required')
        ->not->toContain('code=');
});

it('ignores a login_hint that is not an email address', function (): void {
    $clientId = hostedApp();

    authorizeRequest(hostedAppRequest($clientId, ['login_hint' => '<script>alert(1)</script>']))->assertRedirect(route('login'));

    $this->get(route('login'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('email', ''));
});

it('offers to start over at the app when a pending step has gone stale', function (): void {
    $clientId = hostedApp(firstParty: false);

    hostedMember();

    // The consent screen, then the step is spent from another tab.
    $props = consentScreen(hostedAppRequest($clientId));
    session()->forget('oauth.pending');

    $this->post((string) $props['approveHref'])->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('oauth/consent')
        ->where('error', __('oauth.failure.stale'))
        ->where('startOver.name', 'Cbox Billing')
        ->where('startOver.url', 'https://billing.example.com'));
});

it('offers only the account when a stale step belongs to no app this browser knows', function (): void {
    hostedMember();

    $this->get(route('oauth.authorize.review', '01HZZZZZZZZZZZZZZZZZZZZZZZ'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('error', __('oauth.failure.stale'))
            ->where('startOver', null));
});

it('gives the passkey challenge behind the address field a longer life than the button\'s', function (): void {
    $this->postJson(route('passkeys.login.options'))->assertOk()->assertJsonPath('timeout', 60000);
    $button = (int) session('passkey.auth_challenge')['exp'];

    $this->postJson(route('passkeys.login.options'), ['autofill' => true])->assertOk()
        ->assertJsonPath('timeout', 600000)
        ->assertJsonPath('userVerification', 'required');

    expect((int) session('passkey.auth_challenge')['exp'] - $button)->toBeGreaterThanOrEqual(470);
});
