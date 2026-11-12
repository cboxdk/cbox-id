<?php

declare(strict_types=1);

use App\Http\Middleware\StatelessFrontendApi;
use App\Platform\FrontendApi\LoginTicket;
use App\Platform\FrontendApi\LoginTicketRefused;
use App\Platform\FrontendApi\LoginTickets;
use App\Platform\PlatformAuth;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\FrontendApi\Models\PublishableKey;
use Cbox\Id\Identity\Contracts\Mfa;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * LOGIN-HOST MODE: the frontend API holds no session, and mints no ticket for an operator.
 *
 * `loginHostMode()` and the host names live in tests/Pest.php.
 */
const EMBEDDING_ORIGIN = 'https://shop.acme.test';
const PASSPHRASE = 'a-strong-unbreached-passphrase';

/** A publishable key and a person in the environment this deployment signs people in to. */
function embeddedSignInFixture(bool $split): PublishableKey
{
    config(['cbox-id.frontend_api.enabled' => true]);
    (new FrontendApiServiceProvider(app()))->boot();

    if ($split) {
        loginHostMode();
        app(EnvironmentContext::class)->set(platformRootEnvironment());
    }

    app(Subjects::class)->create('ada@acme.test', 'Ada', PASSPHRASE);

    return app(PublishableKeys::class)->issue('Shop', KeyMode::Test, [EMBEDDING_ORIGIN]);
}

function embeddedSignIn(PublishableKey $key, string $email, string $host = ''): TestResponse
{
    return test()->withHeaders(['X-Cbox-Publishable-Key' => $key->key, 'Origin' => EMBEDDING_ORIGIN])
        ->postJson(($host === '' ? '' : 'https://'.$host).'/frontend/v1/sign-in', ['email' => $email, 'password' => PASSPHRASE]);
}

/** An operator of the platform, with or without a confirmed authenticator app. */
function operatorWithPassword(string $email, bool $totp): void
{
    $operator = app(PlatformOperators::class)->create($email, PASSPHRASE, 'Operator');

    if ($totp) {
        $enrollment = app(Mfa::class)->enrollTotp((string) $operator->subject_id, $email);
        confirmTotpForTest((string) $operator->subject_id, $enrollment->secret);
    }
}

it('runs on the session cookie in single-host mode, as it always has', function (): void {
    // WHAT THE SPLIT CLOSES, written down as it stands. The endpoint is in the `web` group
    // and answers with credentialed CORS, so the answer carries the issuer's session cookie
    // and the request acts on whatever session the browser sent with it.
    $key = embeddedSignInFixture(split: false);
    $other = app(Subjects::class)->create('grace@acme.test', 'Grace', PASSPHRASE);

    // Somebody is signed in at the issuer in this browser…
    app(PlatformAuth::class)->establish(request(), $other->id, ['pwd']);
    expect(session(PlatformAuth::SESSION_KEY))->not->toBeNull();

    $response = embeddedSignIn($key, 'ada@acme.test')->assertOk()->assertJsonPath('status', 'ok');

    $cookies = array_map(static fn (Cookie $cookie): string => $cookie->getName(), $response->headers->getCookies());

    expect($response->headers->get('Access-Control-Allow-Credentials'))->toBe('true')
        ->and($cookies)->toContain(config('session.cookie'))
        // …and the embedded sign-in of somebody else signed them out of it.
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull();
})->group('security');

it('answers a sign-in with a ticket and no cookie on the login host', function (): void {
    $key = embeddedSignInFixture(split: true);

    $response = embeddedSignIn($key, 'ada@acme.test', LOGIN_HOST)->assertOk()->assertJsonPath('status', 'ok');

    expect($response->json('login_ticket'))->toBeString()
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse()
        // The origin allow-list still answers: the page may read the ticket.
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe(EMBEDDING_ORIGIN)
        ->and(config('session.driver'))->not->toBeNull();

    // Preflight too.
    $preflight = $this->withHeaders(['X-Cbox-Publishable-Key' => $key->key, 'Origin' => EMBEDDING_ORIGIN])
        ->options('https://'.LOGIN_HOST.'/frontend/v1/sign-in');

    expect($preflight->headers->has('Access-Control-Allow-Credentials'))->toBeFalse()
        ->and($preflight->headers->getCookies())->toBe([]);
})->group('security');

it('hands the request on with no cookies and a session that lives for that request only', function (): void {
    loginHostMode();
    $driver = config('session.driver');

    $request = Request::create('https://'.LOGIN_HOST.'/frontend/v1/sign-in', 'POST', cookies: ['__Host-cbox-sso' => 'the-sso-session']);
    $request->headers->set('Cookie', '__Host-cbox-sso=the-sso-session');

    $response = app(StatelessFrontendApi::class)->handle($request, function (Request $inner): Response {
        expect($inner->cookies->all())->toBe([])
            ->and($inner->headers->has('Cookie'))->toBeFalse()
            ->and(config('session.driver'))->toBe('array');

        return (new Response('ok'))
            ->withCookie(new Cookie('__Host-cbox-sso', 'minted'))
            ->header('Access-Control-Allow-Credentials', 'true');
    });

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse()
        ->and(config('session.driver'))->toBe($driver);

    // Any other path keeps its cookies.
    $page = Request::create('https://'.LOGIN_HOST.'/login', 'GET', cookies: ['__Host-cbox-sso' => 'the-sso-session']);

    app(StatelessFrontendApi::class)->handle($page, function (Request $inner): Response {
        expect($inner->cookies->all())->toBe(['__Host-cbox-sso' => 'the-sso-session']);

        return new Response('ok');
    });
})->group('security');

it('mints no login ticket for a platform operator', function (bool $totp): void {
    $key = embeddedSignInFixture(split: true);
    operatorWithPassword('op@platform.test', $totp);

    $response = embeddedSignIn($key, 'op@platform.test', LOGIN_HOST)->assertOk();

    expect($response->json('status'))->toBe('hosted_sign_in_required')
        ->and($response->json())->not->toHaveKey('login_ticket')
        ->and($response->json())->not->toHaveKey('mfa_token')
        ->and(LoginTicket::query()->count())->toBe(0);

    // Whoever asks, and however far along the sign-in is.
    $subjectId = (string) app(PlatformOperators::class)->findByEmail('op@platform.test')?->subject_id;

    expect(fn () => app(LoginTickets::class)->mint($key, $subjectId, ['pwd']))->toThrow(LoginTicketRefused::class)
        ->and(fn () => app(LoginTickets::class)->mintPending($key, $subjectId, ['pwd'], 'pending_mfa'))->toThrow(LoginTicketRefused::class);

    // Everybody else still gets one.
    embeddedSignIn($key, 'ada@acme.test', LOGIN_HOST)->assertOk()->assertJsonPath('status', 'ok');
})->with([
    'with an authenticator app' => true,
    'with a password alone' => false,
])->group('security');

it('keeps minting for an operator in single-host mode', function (): void {
    $key = embeddedSignInFixture(split: false);
    platformRootDeployment();
    operatorWithPassword('op@platform.test', totp: false);

    $subjectId = (string) app(PlatformOperators::class)->findByEmail('op@platform.test')?->subject_id;

    expect(app(LoginTickets::class)->refuses($subjectId))->toBeFalse()
        ->and(app(LoginTickets::class)->mint($key, $subjectId, ['pwd']))->toBeString();
});
