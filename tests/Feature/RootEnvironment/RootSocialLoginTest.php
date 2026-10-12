<?php

declare(strict_types=1);

use App\Platform\Console\HandoffTarget;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| GOOGLE ON CBOXID.COM — set up in the platform's own environment, used everywhere a root
| account signs in
|--------------------------------------------------------------------------
|
| The owner's sentence, end to end: an operator turns Google on for the platform root's
| environment, environment-wide, from that environment's console — and the button is on
| `cboxid.com/login`. Somebody who starts at a tenant's console (`acme.cboxid.com/admin/
| users`) signed out is sent to that sign-in, signs in with Google, and lands back on the
| page they asked for — through the one redirect URI the operator registered with Google,
| on the root, and the signed POST handoff, never a token in a URL.
|
| The provider is real OpenID Connect against a faked Google: a real RSA key, a real
| RS256 id_token carrying the nonce the application issued, and the JWKS that verifies it.
*/

const SOCIAL_ROOT_HOST = 'cboxid.com';

beforeEach(function (): void {
    multiTenantDeployment(SOCIAL_ROOT_HOST);
    config([
        'cbox-id.environments.base_domains' => [SOCIAL_ROOT_HOST],
        // A real DNS lookup for accounts.google.com otherwise, on every outbound call.
        'cbox-id.federation.verify_url' => false,
    ]);

    Http::fake([
        'accounts.google.com/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://accounts.google.com',
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
        ]),
        'api.pwnedpasswords.com/*' => Http::response('', 200),
    ]);

    $this->account = provisionAccount('owner@acme.example');
    $this->tenantHost = $this->account['environment']->slug.'.'.SOCIAL_ROOT_HOST;
});

/** A platform operator, signed in at the root with a confirmed address. */
function rootOperatorSignedIn(): string
{
    $existing = PlatformOperator::query()->where('email', 'ops@platform.test')->first();

    if ($existing !== null) {
        signInAsSubject((string) $existing->subject_id);

        return (string) $existing->subject_id;
    }

    $operator = actAsOperator('ops@platform.test');
    $subjectId = (string) $operator->subject_id;

    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->markEmailVerified($subjectId, 'ops@platform.test'));

    return $subjectId;
}

/** Google, environment-wide, in the platform root's environment — as an operator does it, in its console. */
function turnOnGoogleForThePlatform(): Connection
{
    test()->from('https://'.SOCIAL_ROOT_HOST.'/admin/social-sign-in?provider=google')
        ->post('https://'.SOCIAL_ROOT_HOST.'/admin/social-sign-in', [
            'provider' => 'google',
            'clientId' => 'google-client-id',
            'clientSecret' => 'google-client-secret',
            'parameters' => [],
            'forEnvironment' => true,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $root = app(PlatformRoot::class)->model();

    return app(EnvironmentContext::class)->runAs($root, static fn (): Connection => Connection::query()->where('provider', 'google')->sole());
}

/** Forget everybody: the next request is a stranger's browser. */
function signedOutBrowser(): void
{
    app()->forgetScopedInstances();
    session()->flush();
}

/**
 * Click Google on the root's sign-in page and come back from Google signed in as $email —
 * returning the callback's response.
 *
 * @param  array<string, mixed>  $claims
 */
function signInWithGoogleAtTheRoot(string $email, array $claims = [], ?string $tamperState = null): TestResponse
{
    $login = test()->get('https://'.SOCIAL_ROOT_HOST.'/login')->assertOk();
    $google = collect($login->inertiaProps('providers'))->firstWhere('provider', 'google');

    expect($google)->not->toBeNull('the root\'s sign-in page offers no Google button');

    $start = test()->get((string) $google['url']);
    $authorize = (string) $start->headers->get('Location');
    parse_str((string) parse_url($authorize, PHP_URL_QUERY), $query);

    // ONE redirect URI, on the root: the address the operator registered with Google.
    expect($authorize)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and((string) $query['redirect_uri'])->toStartWith('https://'.SOCIAL_ROOT_HOST.'/sso/oidc/')
        ->and((string) $query['redirect_uri'])->toEndWith('/callback');

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $details = openssl_pkey_get_details($key);
    $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['id_token' => JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'google-client-id',
            'sub' => 'google|'.md5($email),
            'email' => $email,
            'email_verified' => true,
            'name' => 'Google Person',
            'nonce' => $query['nonce'],
            'iat' => time(),
            'exp' => time() + 300,
            ...$claims,
        ], $pem, 'RS256', 'k1')]),
        'www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [[
            'kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]]),
    ]);

    return test()->get((string) $query['redirect_uri'].'?'.http_build_query(['code' => 'the-code', 'state' => $tamperState ?? $query['state']]));
}

/** Follow the root's open-environment door into the tenant's console, by its signed POST. */
function handOffToTheTenant(string $openUrl, string $tenantHost): TestResponse
{
    $door = test()->get($openUrl)->assertOk();
    $fields = $door->viewData('fields');
    $action = (string) $door->viewData('action');

    // The credential travels in a form body to the tenant's own host — never in a URL.
    expect($action)->toBe('https://'.$tenantHost.'/admin/handoff')
        ->and($openUrl)->not->toContain('token=');

    return test()->post($action, $fields);
}

it('puts the platform environment\'s Google on cboxid.com/login, for everybody', function (): void {
    rootOperatorSignedIn();
    $google = turnOnGoogleForThePlatform();

    expect($google->organization_id)->toBeNull();

    signedOutBrowser();

    $providers = collect($this->get('https://'.SOCIAL_ROOT_HOST.'/login')->assertOk()->inertiaProps('providers'));

    expect($providers->pluck('provider')->all())->toContain('google')
        ->and((string) $providers->firstWhere('provider', 'google')['url'])->toStartWith('https://'.SOCIAL_ROOT_HOST.'/sso/oidc/'.$google->id);

    // …and the setup form showed the operator the exact URI to register, on the root.
    rootOperatorSignedIn();
    $page = $this->get('https://'.SOCIAL_ROOT_HOST.'/admin/social-sign-in')->assertOk();

    expect(json_encode($page->inertiaProps('environmentProviders')))->toContain(str_replace('/', '\/', 'https://'.SOCIAL_ROOT_HOST.'/sso/oidc/'.$google->id.'/callback'))
        ->and($page->inertiaProps('audience.kind'))->toBe('platform');
});

it('does not let a workspace owner who is not an operator set the platform\'s Google', function (): void {
    signInAsSubject($this->account['subjectId']);

    // Not the environment console — it is not theirs…
    $this->post('https://'.SOCIAL_ROOT_HOST.'/admin/social-sign-in', [
        'provider' => 'google', 'clientId' => 'x', 'clientSecret' => 'y', 'parameters' => [], 'forEnvironment' => true,
    ])->assertNotFound();

    // …and not the workspace console's old Social login, which configured their own
    // organization while looking like the platform's: it is not a page there any more.
    $this->post('https://'.SOCIAL_ROOT_HOST.'/social-sign-in', [
        'provider' => 'google', 'clientId' => 'x', 'clientSecret' => 'y', 'parameters' => [],
    ])->assertNotFound();

    $root = app(PlatformRoot::class)->model();

    expect(app(EnvironmentContext::class)->runAs($root, static fn (): int => Connection::query()->count()))->toBe(0);
})->group('security');

it('takes somebody who starts at a tenant\'s console signed out through Google at the root and back to the page they asked for', function (): void {
    rootOperatorSignedIn();
    turnOnGoogleForThePlatform();
    signedOutBrowser();

    // 1. The tenant's console, signed out: to the root's door, carrying the page.
    $bounce = $this->get('https://'.$this->tenantHost.'/admin/users');
    $open = (string) $bounce->headers->get('Location');

    expect($open)->toBe('https://'.SOCIAL_ROOT_HOST.'/open/'.$this->account['environment']->id.'?to=%2Fadmin%2Fusers');

    // 2. The root's door, signed out: to the root's sign-in.
    $this->get($open)->assertRedirect('https://'.SOCIAL_ROOT_HOST.'/login');

    // 3. Google, at the root — the person is new to Cbox ID, and becomes an admin of the
    //    workspace before they open its console (as an invitation would make them).
    $callback = signInWithGoogleAtTheRoot('new.admin@acme.example');
    $subject = app(PlatformRoot::class)->run(fn () => app(Subjects::class)->findByEmail('new.admin@acme.example'));
    expect($subject)->not->toBeNull();
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->add($this->account['organization']->id, (string) $subject?->id, MembershipRole::Admin));

    // Back to the door they came through, with the page still on it.
    expect((string) $callback->headers->get('Location'))->toBe($open);

    // 4. The signed POST handoff, and the page they asked for.
    $landed = handOffToTheTenant($open, $this->tenantHost);

    expect((string) $landed->headers->get('Location'))->toEndWith('/admin/users');

    $this->get('https://'.$this->tenantHost.'/admin/users')->assertOk();
    expect(app(EnvironmentAdminAuth::class)->subjectId())->toBe($subject?->id);
});

it('opens a tenant\'s console straight away for somebody already signed in at the root', function (): void {
    signInAsSubject($this->account['subjectId']);

    $open = (string) $this->get('https://'.$this->tenantHost.'/admin/roles')->headers->get('Location');

    expect($open)->toContain('to=%2Fadmin%2Froles');

    $landed = handOffToTheTenant($open, $this->tenantHost);

    expect((string) $landed->headers->get('Location'))->toEndWith('/admin/roles');
});

it('refuses a callback whose state is not the one this browser was given', function (): void {
    rootOperatorSignedIn();
    turnOnGoogleForThePlatform();
    signedOutBrowser();

    $callback = signInWithGoogleAtTheRoot('someone@acme.example', tamperState: 'forged-state');

    $callback->assertRedirect(route('login'));
    expect(session(PlatformAuth::SESSION_KEY))->toBeNull();
})->group('security');

it('drops a page to land on that is not one of the environment console\'s own', function (): void {
    signInAsSubject($this->account['subjectId']);
    $environmentId = $this->account['environment']->id;

    foreach (['https://evil.example/admin', '//evil.example/admin', '/admin/../../oauth/authorize', '/dashboard', '/admin/users?x=1'] as $foreign) {
        $door = $this->get('https://'.SOCIAL_ROOT_HOST.'/open/'.$environmentId.'?'.http_build_query(['to' => $foreign]))->assertOk();

        expect($door->viewData('fields'))->not->toHaveKey('to');
    }

    // …and a signature that does not match the page is not honoured on the other side.
    $door = $this->get('https://'.SOCIAL_ROOT_HOST.'/open/'.$environmentId.'?to=%2Fadmin%2Fusers')->assertOk();
    $fields = $door->viewData('fields');
    $fields['to'] = '/admin/roles';

    $landed = $this->post('https://'.$this->tenantHost.'/admin/handoff', $fields);

    expect((string) $landed->headers->get('Location'))->not->toEndWith('/admin/roles');
    expect(app(HandoffTarget::class)->verify((string) $fields['token'], '/admin/roles', (string) $fields['to_sig']))->toBeNull();
})->group('security');

it('tells a root account that administers nothing there so, rather than answering a blank 403', function (): void {
    rootOperatorSignedIn();
    turnOnGoogleForThePlatform();
    signedOutBrowser();

    $open = (string) $this->get('https://'.$this->tenantHost.'/admin/users')->headers->get('Location');

    // Signed in with Google — successfully — as somebody with no part in this workspace.
    signInWithGoogleAtTheRoot('stranger@elsewhere.example');

    $refused = $this->get($open)->assertForbidden();

    expect($refused->inertiaPage()['component'])->toBe('console/environment-refused')
        ->and($refused->inertiaProps('refusedEnvironment.name'))->toBe(Environment::query()->find($this->account['environment']->id)?->name)
        ->and($refused->inertiaProps('email'))->toBe('stranger@elsewhere.example');
});

it('keeps a tenant\'s own Google off its console\'s sign-in, which is the root\'s', function (): void {
    // The tenant turns on Google for ITS users, environment-wide, in its own console.
    actAsEnvironmentAdmin($this->account['subjectId'], $this->account['environment']->id);

    $this->from('https://'.$this->tenantHost.'/admin/social-sign-in?provider=google')
        ->post('https://'.$this->tenantHost.'/admin/social-sign-in', [
            'provider' => 'google', 'clientId' => 'tenant-google', 'clientSecret' => 'tenant-secret', 'parameters' => [], 'forEnvironment' => true,
        ])->assertSessionHasNoErrors();

    $page = $this->get('https://'.$this->tenantHost.'/admin/social-sign-in')->assertOk();

    // The page says whose buttons these are, and that the console is signed in elsewhere.
    expect($page->inertiaProps('audience'))->toMatchArray(['kind' => 'environment', 'consoleHost' => SOCIAL_ROOT_HOST]);

    signedOutBrowser();

    // The tenant's end users see it on the tenant's sign-in page…
    expect(collect($this->get('https://'.$this->tenantHost.'/login')->inertiaProps('providers'))->pluck('provider')->all())->toContain('google');

    // …and the console does not offer a tenant sign-in at all: it sends them to the root,
    // whose sign-in page has no tenant's buttons on it.
    $this->get('https://'.$this->tenantHost.'/admin')->assertRedirect('https://'.SOCIAL_ROOT_HOST.'/open/'.$this->account['environment']->id);
    expect(collect($this->get('https://'.SOCIAL_ROOT_HOST.'/login')->inertiaProps('providers'))->pluck('provider')->all())->not->toContain('google');
});
