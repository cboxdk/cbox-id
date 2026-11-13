<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\ConsoleSignIn\ConsoleClient;
use App\Platform\ConsoleSignIn\ConsoleSignIn;
use App\Platform\EnvironmentSudo;
use App\Platform\NativeProduct\NativeProductClient;
use App\Platform\NativeProduct\NativeProductManifest;
use App\Platform\Operators\OperatorAssurance;
use App\Platform\Organizations\OrganizationDirectory;
use App\Platform\PlatformAuth;
use App\Platform\SignInLineage;
use App\Platform\StepUpPrompt;
use App\Platform\Sudo;
use App\Platform\Surfaces\SessionSurface;
use Cbox\Id\AccessControl\Contracts\AppManifests;
use Cbox\Id\Identity\Contracts\AdminPasswords;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\Enums\PasswordRevocationScope;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Identity\ValueObjects\AdminPasswordAssignment;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Contracts\LogoutTokenIssuer;
use Cbox\Id\OAuthServer\Enums\AuthMethod;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Support\SessionIdentifier;
use Cbox\Id\OAuthServer\ValueObjects\LogoutNotice;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * LOGIN-HOST MODE: the console on the app host is a relying party of the login host.
 *
 * The test client has ONE Laravel session where a browser has a cookie per host, so each
 * leg swaps it: {@see atTheLoginHost()} is the browser's SSO cookie, {@see atTheConsole()}
 * its console cookie, each with the surface marker a session carries (`cbox.surface`,
 * {@see SessionSurface}).
 */
const RP_LOGIN = 'login.cboxid.test';
const RP_APP = 'app.cboxid.test';

beforeEach(function (): void {
    unset($GLOBALS['rp.tokens']);
});

/**
 * Login-host mode with a customer, its owner, and the console's client provisioned.
 *
 * @return array{subjectId: string, organizationId: string, clientId: string, environmentId: string, console: string}
 */
function consoleRealm(): array
{
    config([
        'app.url' => 'https://'.RP_APP,
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => RP_APP,
        'cbox-id.hosts.login' => RP_LOGIN,
        'cbox-id.hosts.api' => 'api.cboxid.test',
        'cbox-id.environments.base_domains' => ['cboxauth.test'],
        'cbox-id.issuer' => 'https://'.RP_LOGIN,
    ]);

    installedDeployment();
    platformRootEnvironment();
    $account = provisionAccount();

    $provisioned = app(ConsoleClient::class)->provision();
    config(['cbox-id.console.client_secret' => $provisioned['secret']]);

    return [
        'subjectId' => $account['subjectId'],
        'organizationId' => $account['organization']->id,
        'clientId' => $provisioned['client']->client_id,
        'environmentId' => $account['environment']->id,
        // The customer's environment console, on the app host.
        'console' => 'https://'.RP_APP.'/'.app(EnvironmentConsolePath::class)->for($account['environment']->id),
    ];
}

/** The browser's cookie on the login host: an SSO session of this person. */
function atTheLoginHost(string $subjectId, array $amr = ['pwd'], ?string $organizationId = null): Session
{
    $session = app(PlatformRoot::class)->run(
        fn (): Session => app(SessionManager::class)->start($subjectId, $organizationId, $amr),
    );

    session()->flush();
    session([PlatformAuth::SESSION_KEY => $session->id, 'cbox.surface' => 'login']);

    return $session;
}

/**
 * The browser's cookie on the app host.
 *
 * @param  array<string, mixed>  $held  what the console's session held when the browser left
 */
function atTheConsole(array $held = []): void
{
    session()->flush();
    session([...$held, 'cbox.surface' => 'console']);
}

/**
 * The login-host half of the round trip: the authorize request answered for the SSO
 * session, and the code redeemed at the real token endpoint. The token response is then
 * what the console's own call to that endpoint is answered with, so the callback verifies
 * a token the issuer really signed for the code the console really sent.
 *
 * @return string the callback URL the login host sent the browser back to
 */
function answeredByTheLoginHost(string $authorizeUrl, string $verifier): string
{
    $back = test()->get($authorizeUrl);
    $back->assertRedirect();
    $callback = (string) $back->headers->get('Location');

    expect($callback)->toStartWith('https://'.RP_APP.'/auth/callback?');

    parse_str((string) parse_url($callback, PHP_URL_QUERY), $query);
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $asked);

    $tokens = test()->withBasicAuth($asked['client_id'], (string) config('cbox-id.console.client_secret'))
        ->post('https://'.RP_LOGIN.'/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'code_verifier' => $verifier,
            'redirect_uri' => $asked['redirect_uri'],
        ]);
    test()->withoutHeader('Authorization');
    $tokens->assertOk();

    // One stub for the test, answering with the latest exchange: the first fake registered
    // for a URL is the one that answers it.
    $first = ! isset($GLOBALS['rp.tokens']);
    $GLOBALS['rp.tokens'] = $tokens->json();

    if ($first || ! Http::preventingStrayRequests()) {
        Http::fake(['https://'.RP_LOGIN.'/oauth/token' => fn () => Http::response($GLOBALS['rp.tokens'])]);
    }

    return $callback;
}

/** The PKCE verifier the console kept for the authorize URL it just built. */
function heldVerifier(string $authorizeUrl): string
{
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $asked);

    return session(ConsoleSignIn::SESSION_KEY)[$asked['state']]['v'];
}

/**
 * Sign in to the console through the login host, as a browser does.
 *
 * @return array{sso: Session, response: TestResponse}
 */
function signInToTheConsole(string $subjectId, string $from = '/dashboard', array $amr = ['pwd']): array
{
    atTheConsole();

    $refused = test()->get('https://'.RP_APP.$from);
    $refused->assertRedirect('https://'.RP_APP.'/auth/start');

    $start = test()->get('https://'.RP_APP.'/auth/start');
    $start->assertRedirect();
    $authorizeUrl = (string) $start->headers->get('Location');
    $verifier = heldVerifier($authorizeUrl);
    $console = session()->all();

    $sso = atTheLoginHost($subjectId, $amr);
    $callback = answeredByTheLoginHost($authorizeUrl, $verifier);

    atTheConsole($console);

    return ['sso' => $sso, 'response' => test()->get($callback)];
}

it('sends an unauthenticated console request to the login host and back into a console session', function (): void {
    $realm = consoleRealm();

    atTheConsole();
    $start = $this->get('https://'.RP_APP.'/dashboard')
        ->assertRedirect('https://'.RP_APP.'/auth/start');

    $authorizeUrl = (string) $this->get('https://'.RP_APP.'/auth/start')->headers->get('Location');
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $asked);

    expect($authorizeUrl)->toStartWith('https://'.RP_LOGIN.'/oauth/authorize?')
        ->and($asked['client_id'])->toBe($realm['clientId'])
        ->and($asked['redirect_uri'])->toBe('https://'.RP_APP.'/auth/callback')
        ->and($asked['response_type'])->toBe('code')
        ->and($asked['scope'])->toBe('openid')
        ->and($asked['code_challenge_method'])->toBe('S256')
        ->and($asked)->toHaveKeys(['state', 'nonce', 'code_challenge'])
        ->and($asked)->not->toHaveKeys(['max_age', 'acr_values', 'prompt']);

    $verifier = heldVerifier($authorizeUrl);
    $console = session()->all();

    $sso = atTheLoginHost($realm['subjectId']);
    $callback = answeredByTheLoginHost($authorizeUrl, $verifier);

    atTheConsole($console);
    $this->get($callback)->assertRedirect('https://'.RP_APP.'/dashboard');

    // A session row of its own, for the person the token named, tied to the SSO session.
    $consoleSessionId = session(PlatformAuth::SESSION_KEY);
    $row = Session::query()->withoutGlobalScopes()->find($consoleSessionId);

    expect($consoleSessionId)->toBeString()->not->toBe($sso->id)
        ->and($row?->user_id)->toBe($realm['subjectId'])
        ->and(DB::table(SignInLineage::TABLE)->where('session_id', $consoleSessionId)->pluck('parent_session_id')->all())->toBe([$sso->id])
        // The code was sent to the token endpoint once, with the verifier, as the client.
        ->and(session(ConsoleSignIn::SESSION_KEY))->toBe([]);

    Http::assertSentCount(1);

    $this->get('https://'.RP_APP.'/dashboard')->assertRedirect();
    expect($this->get('https://'.RP_APP.'/dashboard')->headers->get('Location'))->not->toContain('/auth/start');
})->group('security');

it('has no console door in single-host mode', function (): void {
    installedDeployment();
    platformRootEnvironment();

    $this->get('/auth/start')->assertNotFound();
    $this->get('/auth/callback?state=x&code=y')->assertNotFound();
    $this->post('/auth/backchannel-logout', ['logout_token' => 'x'])->assertNotFound();
})->group('security');

it('answers a callback it did not ask for with a page, never a redirect back to the start', function (): void {
    consoleRealm();
    atTheConsole();

    $this->get('https://'.RP_APP.'/auth/callback?state=forged&code=whatever')
        ->assertStatus(400)
        ->assertInertia(fn ($page) => $page->component('auth/console-sign-in-failed')->where('reason', 'state'));

    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
    Http::assertNothingSent();
})->group('security');

it('refuses a token that answers another request (nonce) and starts no session', function (): void {
    $realm = consoleRealm();

    atTheConsole();
    $first = (string) $this->get('https://'.RP_APP.'/auth/start')->headers->get('Location');
    $second = (string) $this->get('https://'.RP_APP.'/auth/start')->headers->get('Location');
    $verifier = heldVerifier($first);
    $console = session()->all();

    atTheLoginHost($realm['subjectId']);
    $callback = answeredByTheLoginHost($first, $verifier);

    // The code and token of the first request, returned under the second request's state.
    parse_str((string) parse_url($second, PHP_URL_QUERY), $other);
    $swapped = preg_replace('/state=[^&]+/', 'state='.$other['state'], $callback);

    atTheConsole($console);
    $this->get($swapped)
        ->assertStatus(400)
        ->assertInertia(fn ($page) => $page->where('reason', 'id_token_nonce'));

    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
})->group('security');

it('does not accept a session from the login surface on the console', function (): void {
    $realm = consoleRealm();

    // The SSO cookie's session, presented to the app host.
    atTheLoginHost($realm['subjectId']);

    $this->get('https://'.RP_APP.'/dashboard')->assertRedirect('https://'.RP_APP.'/auth/start');
})->group('security');

it('tells an Inertia visit to leave the page, and a JSON call where the door is', function (): void {
    consoleRealm();
    atTheConsole();

    // A write: an Inertia GET is answered 409 for a stale asset version before any gate.
    inertiaRequest(fn () => $this->delete('https://'.RP_APP.'/organization'))
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://'.RP_APP.'/auth/start');

    $this->getJson('https://'.RP_APP.'/dashboard')
        ->assertUnauthorized()
        ->assertJsonPath('redirect', 'https://'.RP_APP.'/auth/start');
});

it('lands on the organization the app switcher named', function (): void {
    $realm = consoleRealm();
    $slug = app(OrganizationDirectory::class)->slugOf($realm['organizationId']);

    expect($slug)->toBeString();

    // Signed out: `/?organization=` survives the trip to the login host.
    atTheConsole();
    $this->get('https://'.RP_APP.'/?organization='.$realm['organizationId'])
        ->assertRedirect('https://'.RP_APP.'/dashboard?organization='.$realm['organizationId']);

    $signedIn = signInToTheConsole($realm['subjectId'], '/dashboard?organization='.$realm['organizationId']);
    $signedIn['response']->assertRedirect('https://'.RP_APP.'/dashboard?organization='.$realm['organizationId']);

    $this->get('https://'.RP_APP.'/dashboard?organization='.$realm['organizationId'])
        ->assertRedirect('https://'.RP_APP.'/orgs/'.$slug);

    // By slug too, and through the door when already signed in.
    $this->get('https://'.RP_APP.'/auth/start?organization='.$slug)
        ->assertRedirect('https://'.RP_APP.'/orgs/'.$slug);

    // An organization the person is not in is ignored: they land where they would have.
    expect($this->get('https://'.RP_APP.'/dashboard?organization=org_somebody_elses')->headers->get('Location'))
        ->not->toContain('org_somebody_elses');
});

it('steps up by a trip to the login host and opens the window from auth_time', function (): void {
    $realm = consoleRealm();
    signInToTheConsole($realm['subjectId']);

    // A gated write in the customer's environment console is refused in place, with the
    // way to the login host and no password endpoint to post to.
    $gated = $realm['console'].'/token-vault';
    $refused = inertiaRequest(fn () => $this->post($gated));
    $door = 'https://'.RP_APP.'/auth/start?step_up=environment';

    $refused->assertForbidden()
        ->assertHeader(StepUpPrompt::HEADER, $door)
        ->assertJsonPath('step_up.plane', 'environment')
        ->assertJsonPath('step_up.confirm', $door)
        ->assertJsonPath('step_up.redirect.page', $door)
        ->assertJsonPath('step_up.redirect.popup', $door.'&popup=1');

    $before = session(PlatformAuth::SESSION_KEY);
    $authorizeUrl = (string) $this->get($door.'&popup=1')->headers->get('Location');
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $asked);

    expect($asked['max_age'])->toBe('0')
        ->and($asked)->not->toHaveKey('acr_values');

    $verifier = heldVerifier($authorizeUrl);
    $console = session()->all();

    // The login host re-authenticates: a new SSO session, with a fresh auth_time.
    $sso = atTheLoginHost($realm['subjectId']);
    $callback = answeredByTheLoginHost($authorizeUrl, $verifier);

    atTheConsole($console);
    $this->get($callback)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/step-up-done')
            ->where('plane', 'environment')
            ->where('confirmed', true));

    $after = session(PlatformAuth::SESSION_KEY);

    // The window is this environment's, counted from auth_time; the organization plane's
    // stays shut. A new console row is tied to the new SSO session and the old one ended.
    expect(session(EnvironmentSudo::SESSION_KEY))->toBe([
        'environment' => $realm['environmentId'],
        'at' => $sso->created_at->getTimestamp(),
    ])
        ->and(app(Sudo::class)->confirmed())->toBeFalse()
        ->and($after)->not->toBe($before)
        ->and(app(PlatformRoot::class)->run(fn () => app(SessionManager::class)->active($before)))->toBeNull()
        ->and(DB::table(SignInLineage::TABLE)->where('session_id', $after)->pluck('parent_session_id')->all())->toBe([$sso->id]);

    // The page behind the dialog replays the write it was holding, and it is let through.
    expect(inertiaRequest(fn () => $this->post($gated))->getStatusCode())->not->toBe(403);
})->group('security');

it('never takes the environment of a step-up from the URL', function (): void {
    $realm = consoleRealm();
    signInToTheConsole($realm['subjectId']);

    // Nothing asked for a step-up in any environment: the door is opened by hand.
    $authorizeUrl = (string) $this->get('https://'.RP_APP.'/auth/start?step_up=environment&environment='.$realm['environmentId'])->headers->get('Location');
    $verifier = heldVerifier($authorizeUrl);
    $console = session()->all();

    atTheLoginHost($realm['subjectId']);
    $callback = answeredByTheLoginHost($authorizeUrl, $verifier);

    atTheConsole($console);
    $this->get($callback)->assertStatus(400);

    expect(session(EnvironmentSudo::SESSION_KEY))->toBeNull();
})->group('security');

it('does not open the window for an authentication that is not fresh', function (): void {
    $realm = consoleRealm();
    signInToTheConsole($realm['subjectId']);

    $door = 'https://'.RP_APP.'/auth/start?step_up=organization';
    $authorizeUrl = (string) $this->get($door)->headers->get('Location');
    $verifier = heldVerifier($authorizeUrl);
    $console = session()->all();

    // An issuer that answered from a sign-in ten minutes old, whatever it was asked.
    $sso = atTheLoginHost($realm['subjectId']);
    $callback = answeredByTheLoginHost(preg_replace('/&max_age=0/', '', $authorizeUrl), $verifier);
    $this->travel(10)->minutes();

    atTheConsole($console);
    $this->get($callback)
        ->assertStatus(400)
        ->assertInertia(fn ($page) => $page->component('auth/console-sign-in-failed')
            ->where('reason', 'step_up_not_met')
            ->where('stepUp', true));

    expect(app(Sudo::class)->confirmed())->toBeFalse();
})->group('security');

it('takes no password on the console in login-host mode', function (): void {
    $realm = consoleRealm();
    signInToTheConsole($realm['subjectId']);

    // `/sudo` is not a console path at all here, and the environment plane's confirm
    // endpoint, which is, refuses.
    $this->post('https://'.RP_APP.'/sudo', ['password' => 'a-strong-unbreached-passphrase'])->assertNotFound();
    $this->post('https://'.RP_APP.'/admin/environment/sudo', ['password' => 'a-strong-unbreached-passphrase'])
        ->assertNotFound();

    expect(app(Sudo::class)->confirmed())->toBeFalse()
        ->and(session(EnvironmentSudo::SESSION_KEY))->toBeNull();
})->group('security');

it('ends the console sessions of an SSO session when the login host says it ended', function (): void {
    $realm = consoleRealm();
    $signedIn = signInToTheConsole($realm['subjectId']);
    $consoleSessionId = session(PlatformAuth::SESSION_KEY);
    $root = app(PlatformRoot::class);

    // Another sign-in of the same person on another device, with a console of its own.
    $console = session()->all();
    signInToTheConsole($realm['subjectId']);
    $otherConsoleSessionId = session(PlatformAuth::SESSION_KEY);

    $client = app(ConsoleClient::class)->find();
    $token = $root->run(fn (): string => app(LogoutTokenIssuer::class)->issue(
        $client,
        new LogoutNotice($client->client_id, $realm['subjectId'], SessionIdentifier::sid($signedIn['sso']->id)),
    ));

    // Server to server: no session, no cookie.
    session()->flush();
    $this->post('https://'.RP_APP.'/auth/backchannel-logout', ['logout_token' => $token])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($root->run(fn () => app(SessionManager::class)->active($consoleSessionId)))->toBeNull()
        ->and($root->run(fn () => app(SessionManager::class)->active($otherConsoleSessionId)))->not->toBeNull();

    // The browser that held it is signed out on its next request.
    atTheConsole($console);
    session([PlatformAuth::SESSION_KEY => $consoleSessionId]);
    $this->get('https://'.RP_APP.'/dashboard')->assertRedirect('https://'.RP_APP.'/auth/start');

    // Anything that is not a logout token for this client is refused.
    $this->post('https://'.RP_APP.'/auth/backchannel-logout', ['logout_token' => 'not-a-token'])->assertStatus(400);
    $this->post('https://'.RP_APP.'/auth/backchannel-logout')->assertStatus(400);
})->group('security');

it('signs out of the console by ending the family and leaving for the login host', function (): void {
    $realm = consoleRealm();
    $signedIn = signInToTheConsole($realm['subjectId']);
    $consoleSessionId = session(PlatformAuth::SESSION_KEY);
    $root = app(PlatformRoot::class);

    $out = $this->post('https://'.RP_APP.'/logout');
    $out->assertRedirect();
    $to = (string) $out->headers->get('Location');
    parse_str((string) parse_url($to, PHP_URL_QUERY), $query);

    expect($to)->toStartWith('https://'.RP_LOGIN.'/oauth/logout?')
        ->and($query['client_id'])->toBe($realm['clientId'])
        ->and($query['post_logout_redirect_uri'])->toBe('https://'.RP_APP.'/signed-out')
        // No hint: a hint is how the login host is asked to end every device's session.
        ->and($query)->not->toHaveKey('id_token_hint')
        ->and($root->run(fn () => app(SessionManager::class)->active($consoleSessionId)))->toBeNull()
        ->and($root->run(fn () => app(SessionManager::class)->active($signedIn['sso']->id)))->toBeNull()
        ->and(session(PlatformAuth::SESSION_KEY))->toBeNull();

    // The login host sends the browser back to a page whose "Sign in" is the door.
    session()->flush();
    $this->get($to)->assertRedirect('https://'.RP_APP.'/signed-out');
    $this->get('https://'.RP_APP.'/signed-out')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('signInUrl', 'https://'.RP_APP.'/auth/start'));
})->group('security');

it('provisions the console client once and keeps its addresses in line', function (): void {
    $realm = consoleRealm();
    $console = app(ConsoleClient::class);
    $client = $console->find();

    expect($client->first_party)->toBeTrue()
        ->and($client->organization_id)->toBeNull()
        ->and($client->redirect_uris)->toBe(['https://'.RP_APP.'/auth/callback'])
        ->and($client->post_logout_redirect_uris)->toBe(['https://'.RP_APP.'/signed-out'])
        ->and($client->backchannel_logout_uri)->toBe('https://'.RP_APP.'/auth/backchannel-logout')
        ->and($client->backchannel_logout_session_required)->toBeTrue();

    // Again: the same client, and no secret is handed out a second time.
    $again = $console->provision();

    expect($again['created'])->toBeFalse()
        ->and($again['secret'])->toBeNull()
        ->and($again['client']->client_id)->toBe($realm['clientId']);

    // The app host moved: the addresses follow, the client and its secret stay.
    config(['cbox-id.tenancy.account_host' => 'console.cboxid.test']);
    $moved = $console->provision();

    expect($moved['client']->client_id)->toBe($realm['clientId'])
        ->and($moved['client']->redirect_uris)->toBe(['https://console.cboxid.test/auth/callback'])
        ->and($moved['secret'])->toBeNull();

    $this->artisan('cbox-id:console:client', ['--rotate' => true, '--secret-only' => true])->assertSuccessful();
});

it('carries the sign-in methods and the organization of the SSO session into the console session', function (): void {
    $realm = consoleRealm();
    signInToTheConsole($realm['subjectId']);

    // Asked to be signed in to one organization (one that requires its own identity
    // provider sends the console here): the login host is asked for exactly that one.
    $authorizeUrl = (string) $this->get('https://'.RP_APP.'/auth/start?enter='.$realm['organizationId'])->headers->get('Location');
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $asked);

    expect($asked['organization'])->toBe($realm['organizationId']);

    $verifier = heldVerifier($authorizeUrl);
    $console = session()->all();

    atTheLoginHost($realm['subjectId'], ['sso'], $realm['organizationId']);
    $callback = answeredByTheLoginHost($authorizeUrl, $verifier);

    atTheConsole($console);
    $this->get($callback)->assertRedirect();

    $row = Session::query()->withoutGlobalScopes()->find(session(PlatformAuth::SESSION_KEY));

    expect($row->organization_id)->toBe($realm['organizationId'])
        ->and(array_values($row->amr))->toBe(['sso'])
        ->and(session(PlatformAuth::ORG_KEY))->toBe($realm['organizationId']);
})->group('security');

it('refuses an answer for another organization than the one it asked to enter', function (): void {
    $realm = consoleRealm();
    signInToTheConsole($realm['subjectId']);
    $held = session(PlatformAuth::SESSION_KEY);

    $authorizeUrl = (string) $this->get('https://'.RP_APP.'/auth/start?enter='.$realm['organizationId'])->headers->get('Location');
    $verifier = heldVerifier($authorizeUrl);
    $console = session()->all();

    // A login host that answered without the organization.
    atTheLoginHost($realm['subjectId']);
    $callback = answeredByTheLoginHost(preg_replace('/&organization=[^&]+/', '', $authorizeUrl), $verifier);
    $token = $GLOBALS['rp.tokens']['id_token'];
    $claims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);

    atTheConsole($console);
    $response = $this->get($callback);

    // Either the login host named the person's own organization anyway, or it named none
    // and the console stopped: it never loops, and never leaves the session it had.
    if (($claims['org'] ?? null) === $realm['organizationId']) {
        $response->assertRedirect();
    } else {
        $response->assertStatus(400)->assertInertia(fn ($page) => $page->where('reason', 'organization_not_entered'));
        expect(session(PlatformAuth::SESSION_KEY))->toBe($held);
    }
})->group('security');

it('opens the operator plane from a passkey proof given at the login host', function (): void {
    $realm = consoleRealm();
    $signedIn = signInToTheConsole($realm['subjectId'], amr: [AuthMethod::Passkey->value, AuthMethod::MultiFactor->value]);

    expect(session(OperatorAssurance::SESSION_KEY))->toMatchArray([
        'session' => session(PlatformAuth::SESSION_KEY),
        'at' => $signedIn['sso']->created_at->getTimestamp(),
    ]);

    // A password sign-in is no such proof.
    signInToTheConsole($realm['subjectId']);

    expect(session(OperatorAssurance::SESSION_KEY)['session'] ?? null)->not->toBe(session(PlatformAuth::SESSION_KEY));
})->group('security');

it('asks the login host for the operator plane\'s passkey class', function (): void {
    $realm = consoleRealm();
    signInToTheConsole($realm['subjectId']);

    $authorizeUrl = (string) $this->get('https://'.RP_APP.'/auth/start?step_up=operator')->headers->get('Location');
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $asked);

    expect($asked['acr_values'])->toBe('urn:cbox-id:phr')
        ->and($asked['max_age'])->toBe('0');

    // …and a password re-authentication does not open it.
    $verifier = heldVerifier($authorizeUrl);
    $console = session()->all();
    atTheLoginHost($realm['subjectId']);
    $callback = answeredByTheLoginHost(preg_replace('/&acr_values=[^&]+/', '', $authorizeUrl), $verifier);

    atTheConsole($console);
    $this->get($callback)->assertStatus(400);

    expect(app(OperatorAssurance::class)->fresh())->toBeFalse();
})->group('security');

it('lands on the page the login host named with return_to, and on no other origin', function (): void {
    $realm = consoleRealm();

    // Signed out: the path rides through the sign-in.
    atTheConsole();
    $authorizeUrl = (string) $this->get('https://'.RP_APP.'/auth/start?return_to='.urlencode('/orgs?tab=1'))->headers->get('Location');
    $verifier = heldVerifier($authorizeUrl);
    $console = session()->all();

    atTheLoginHost($realm['subjectId']);
    $callback = answeredByTheLoginHost($authorizeUrl, $verifier);

    atTheConsole($console);
    $this->get($callback)->assertRedirect('/orgs?tab=1');

    // Signed in: straight there.
    $this->get('https://'.RP_APP.'/auth/start?return_to='.urlencode('/orgs'))->assertRedirect('/orgs');

    // Anything that is not a path on this host, or is the door itself, is ignored.
    foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example', '/auth/start?return_to=/auth/start'] as $bad) {
        $this->get('https://'.RP_APP.'/auth/start?return_to='.urlencode($bad))
            ->assertRedirect('https://'.RP_APP.'/dashboard');
    }
})->group('security');

it('takes over a console client registered before the console was a relying party', function (): void {
    config([
        'app.url' => 'https://'.RP_APP,
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => RP_APP,
        'cbox-id.hosts.login' => RP_LOGIN,
        'cbox-id.issuer' => 'https://'.RP_LOGIN,
    ]);
    installedDeployment();
    platformRootEnvironment();

    // The row the old sign-in-through setup left: same name, one retired redirect address.
    $old = app(PlatformRoot::class)->run(fn () => app(ClientRegistry::class)->register(new NewClient(
        name: ConsoleClient::NAME,
        type: ClientType::Confidential,
        redirectUris: ['https://'.RP_APP.'/sso/oidc/01M71KHWT1J6G9ZNF4VHGKHCT4/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid', 'profile', 'email'],
    ))->client);

    $taken = app(ConsoleClient::class)->provision(rotate: true);

    expect($taken['created'])->toBeFalse()
        ->and($taken['client']->client_id)->toBe($old->client_id)
        ->and($taken['secret'])->toBeString()->not->toBe('')
        ->and($taken['client']->first_party)->toBeTrue()
        ->and($taken['client']->redirect_uris)->toBe(['https://'.RP_APP.'/auth/callback'])
        ->and($taken['client']->post_logout_redirect_uris)->toBe(['https://'.RP_APP.'/signed-out'])
        ->and($taken['client']->backchannel_logout_uri)->toBe('https://'.RP_APP.'/auth/backchannel-logout')
        ->and(Client::query()->withoutGlobalScopes()->where('name', ConsoleClient::NAME)->count())->toBe(1);
});

it('sends a console session that owes a password change to the login host and brings it back to the page it was opening', function (): void {
    $realm = consoleRealm();
    signInToTheConsole($realm['subjectId']);
    $console = session()->all();

    app(PlatformRoot::class)->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $realm['subjectId'],
        password: 'the-handed-over-passphrase',
        temporary: true,
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    )));

    $page = 'https://'.RP_APP.'/dashboard?tab=general';
    $held = (string) $this->get($page)->assertRedirect()->headers->get('Location');

    expect($held)->toBe('https://'.RP_LOGIN.'/password/change?return_to='.urlencode($page));

    // A write names no page: the change then ends on the console's own default.
    expect((string) $this->post('https://'.RP_APP.'/directory/members/leave')->headers->get('Location'))
        ->toBe('https://'.RP_LOGIN.'/password/change');

    atTheLoginHost($realm['subjectId']);
    $this->get($held)->assertOk();

    $done = $this->post('https://'.RP_LOGIN.'/password/change', [
        'password' => 'a-new-strong-unbreached-passphrase',
        'password_confirmation' => 'a-new-strong-unbreached-passphrase',
    ]);

    // Through the console's start, to the page they were opening, on the app host only.
    $done->assertRedirect('https://'.RP_APP.'/auth/start?return_to='.urlencode('/dashboard?tab=general'));

    // An address on another origin is never where the change ends.
    app(PlatformRoot::class)->run(fn () => app(AdminPasswords::class)->assign(new AdminPasswordAssignment(
        userId: $realm['subjectId'],
        password: 'another-handed-over-passphrase',
        temporary: true,
        expiresAt: null,
        revoke: PasswordRevocationScope::Nothing,
    )));
    atTheLoginHost($realm['subjectId']);
    $this->get('https://'.RP_LOGIN.'/password/change?return_to='.urlencode('https://evil.example/x'))->assertOk();

    $location = (string) $this->post('https://'.RP_LOGIN.'/password/change', [
        'password' => 'yet-another-strong-unbreached-passphrase',
        'password_confirmation' => 'yet-another-strong-unbreached-passphrase',
    ])->headers->get('Location');

    expect($location)->toStartWith('https://'.RP_APP.'/auth/start')->not->toContain('evil.example');

    atTheConsole($console);
})->group('security');

it('sends a console session that owes a second factor to the security page on the login host, with the way back', function (): void {
    $realm = consoleRealm();
    signInToTheConsole($realm['subjectId']);

    app(PlatformRoot::class)->run(fn () => app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required)));

    $page = 'https://'.RP_APP.'/dashboard';
    $held = (string) $this->get($page)->assertRedirect()->headers->get('Location');

    expect($held)->toBe('https://'.RP_LOGIN.'/settings?return_to='.urlencode($page));

    atTheLoginHost($realm['subjectId']);

    $this->get($held)->assertOk()
        ->assertInertia(fn ($inertia) => $inertia
            // The chrome's "Back to …" is the one link; the page adds no second one.
            ->where('returnTo', null)
            ->where('accountShell.back.url', $page));
})->group('security');

/**
 * THE FAILURE PAGE IS A DOOR, AND A DOOR STANDS IN NO ENVIRONMENT. It clears the signed-in
 * person, which reads as the environment plane, while the request (`/auth/callback`) names
 * no environment in its URL. The shared Inertia props asked for the environment console's
 * support-session route anyway and threw — production answered every failed sign-in and
 * step-up with a 500 instead of this page. Support is switched on and the person is a
 * signed-in workspace owner, which is when the support URL is computed at all.
 */
it('renders the failure page, not a 500, when support is on and the callback names no environment', function (string $reason): void {
    $this->withoutVite();
    $realm = consoleRealm();
    config(['ai-assistant-connector.support.enabled' => true]);
    app(NativeProductClient::class)->synchronize(app(AppManifests::class), new NativeProductManifest);
    signInToTheConsole($realm['subjectId']);

    if ($reason === 'state') {
        $response = $this->get('https://'.RP_APP.'/auth/callback?state=forged&code=whatever');
    } else {
        $authorizeUrl = (string) $this->get('https://'.RP_APP.'/auth/start?step_up=organization')->headers->get('Location');
        $verifier = heldVerifier($authorizeUrl);
        $console = session()->all();

        atTheLoginHost($realm['subjectId']);
        $callback = answeredByTheLoginHost(preg_replace('/&max_age=0/', '', $authorizeUrl), $verifier);
        $this->travel(10)->minutes();

        atTheConsole($console);
        $response = $this->get($callback);
    }

    $response->assertStatus(400)
        ->assertInertia(fn ($page) => $page->component('auth/console-sign-in-failed')
            ->where('reason', $reason)
            ->where('supportSessionUrl', null));
})->with(['state', 'step_up_not_met']);
