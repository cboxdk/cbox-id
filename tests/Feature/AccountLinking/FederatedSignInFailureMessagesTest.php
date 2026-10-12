<?php

declare(strict_types=1);

use App\Platform\AccountLinking\FederationFailure;
use App\Platform\Locale\LocaleResolver;
use App\Platform\PlatformAuth;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\RootGoogle;

/*
|--------------------------------------------------------------------------
| EVERY FEDERATED SIGN-IN FAILURE SAYS WHY — on the page the person lands on
|--------------------------------------------------------------------------
|
| The production bug these hold shut: the callbacks wrote their reason into the validation
| bag under `email`, the sign-in page reads its errors from its own form, and so every
| reason was dropped. The old tests asserted the SESSION held a message, which it did; none
| asked whether the PAGE showed one. Each test here follows the redirect to the real
| sign-in page and reads what it is drawing — once per failure branch, on the root and on a
| tenant's host, and in the visitor's language.
*/

beforeEach(function (): void {
    RootGoogle::deployment();

    $this->account = provisionAccount('owner@acme.example');
    $this->tenantHost = $this->account['environment']->slug.'.'.RootGoogle::HOST;

    RootGoogle::operatorSignedIn();
    $this->google = RootGoogle::turnOn();
    RootGoogle::signedOutBrowser();
});

/** Follow a federated door's redirect to the sign-in page and read the alert it draws. */
function shownOnSignIn(TestResponse $response, string $host = RootGoogle::HOST): ?string
{
    expect((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH))->toBe('/login');

    $page = test()->get('https://'.$host.'/login')->assertOk();

    expect($page->inertiaPage()['component'])->toBe('auth/login');

    $flash = $page->inertiaPage()['flash'] ?? [];

    return is_array($flash) && is_string($flash['signInProblem'] ?? null) ? $flash['signInProblem'] : null;
}

function switchOff(Connection $connection): void
{
    app(EnvironmentContext::class)->runAs(
        app(PlatformRoot::class)->model(),
        static fn () => Connection::query()->whereKey($connection->id)->update(['status' => ConnectionStatus::Inactive]),
    );
}

it('shows an expired or forged state as an expired sign-in', function (): void {
    $response = RootGoogle::signIn('someone@acme.example', tamperState: 'forged-state');

    expect(shownOnSignIn($response))->toBe(FederationFailure::Expired->message(['provider' => $this->google->name]))
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('shows a Cancel at the provider as a cancellation, not as an expired link', function (): void {
    $response = RootGoogle::signIn('someone@acme.example', answer: ['error' => 'access_denied']);

    expect(shownOnSignIn($response))->toBe(FederationFailure::Cancelled->message(['provider' => $this->google->name]));
});

it('shows a token that does not verify as a rejected sign-in', function (): void {
    // A nonce that is not the one this browser was given.
    $response = RootGoogle::signIn('someone@acme.example', ['nonce' => 'not-ours']);

    expect(shownOnSignIn($response))->toBe(FederationFailure::Rejected->message(['provider' => $this->google->name]))
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull();
});

it('shows an unreachable provider as unreachable', function (): void {
    $login = $this->get('https://'.RootGoogle::HOST.'/login')->assertOk();
    $start = (string) collect($login->inertiaProps('providers'))->firstWhere('provider', 'google')['url'];
    parse_str((string) parse_url((string) $this->get($start)->headers->get('Location'), PHP_URL_QUERY), $query);

    Http::fake(['oauth2.googleapis.com/token' => static fn () => throw new ConnectionException('timed out')]);

    $response = $this->get((string) $query['redirect_uri'].'?'.http_build_query(['code' => 'c', 'state' => $query['state']]));

    expect(shownOnSignIn($response))->toBe(FederationFailure::Unavailable->message(['provider' => $this->google->name]));
});

it('shows a switched-off connection on the callback as unavailable here', function (): void {
    $login = $this->get('https://'.RootGoogle::HOST.'/login')->assertOk();
    $start = (string) collect($login->inertiaProps('providers'))->firstWhere('provider', 'google')['url'];
    parse_str((string) parse_url((string) $this->get($start)->headers->get('Location'), PHP_URL_QUERY), $query);

    switchOff($this->google);

    $response = $this->get((string) $query['redirect_uri'].'?'.http_build_query(['code' => 'c', 'state' => $query['state']]));

    expect(shownOnSignIn($response))->toBe(FederationFailure::Inactive->message(['provider' => $this->google->name]));
});

it('shows a switched-off connection on the button as unavailable here, not a page of JSON', function (): void {
    switchOff($this->google);

    $response = $this->get('https://'.RootGoogle::HOST.'/sso/oidc/'.$this->google->id.'/redirect');

    expect(shownOnSignIn($response))->toBe(FederationFailure::Inactive->message(['provider' => $this->google->name]));
});

it('shows an unknown connection as unavailable, naming no provider it does not have', function (): void {
    $response = $this->get('https://'.RootGoogle::HOST.'/sso/oidc/01JZZZZZZZZZZZZZZZZZZZZZZZ/callback?code=c&state=s');

    expect(shownOnSignIn($response))->toBe(FederationFailure::Inactive->message());
});

it('shows a refused link — an address the provider did not verify — with what to do instead', function (): void {
    $response = RootGoogle::signIn('owner@acme.example', ['email_verified' => false]);

    expect(shownOnSignIn($response))
        ->toBe(FederationFailure::Unverified->message(['provider' => $this->google->name, 'email' => 'owner@acme.example']))
        ->toContain('My account › Sign-in methods');
});

it('says it in the visitor\'s language', function (): void {
    $response = $this->withCookie(LocaleResolver::COOKIE, 'da')->get(
        'https://'.RootGoogle::HOST.'/sso/oidc/'.$this->google->id.'/callback?code=c&state=forged',
    );

    app()->setLocale('da');
    $danish = FederationFailure::Expired->message(['provider' => $this->google->name]);
    app()->setLocale('en');

    expect($danish)->not->toBe(FederationFailure::Expired->message(['provider' => $this->google->name]))
        ->and($this->withCookie(LocaleResolver::COOKIE, 'da')->get('https://'.RootGoogle::HOST.'/login')->inertiaPage()['flash']['signInProblem'] ?? null)
        ->toBe($danish);

    expect((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH))->toBe('/login');
});

it('tells somebody already signed in on My account, in the console\'s English, rather than losing it behind the guest guard', function (): void {
    signInAsSubject($this->account['subjectId']);

    $response = $this->get('https://'.RootGoogle::HOST.'/sso/oidc/'.$this->google->id.'/callback?code=c&state=forged');

    $response->assertRedirect(route('account'))
        ->assertSessionHas('error', FederationFailure::Expired->message(['provider' => $this->google->name], 'en'));
});
