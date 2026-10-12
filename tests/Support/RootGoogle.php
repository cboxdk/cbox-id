<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Platform\PlatformAuth;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * GOOGLE ON cboxid.com, faked end to end — for every test that signs somebody in with it.
 *
 * Lifted out of RootSocialLoginTest when a second file needed it: a helper defined in one
 * test file is not loaded in a parallel worker that runs another, so a shared fixture lives
 * in a class the autoloader can find.
 *
 * The provider is real OpenID Connect against a faked Google: a real RSA key, a real RS256
 * id_token carrying the nonce the application issued, and the JWKS that verifies it. The
 * Google subject is derived from the address unless a test names one, so the same address
 * signs in as the same Google account every time — which is what "the second sign-in goes
 * straight in" depends on.
 */
final class RootGoogle
{
    public const HOST = 'cboxid.com';

    private static ?string $pem = null;

    /** @var list<array<string, string>> */
    private static array $jwks = [];

    private static string $idToken = '';

    /** The SaaS shape with cboxid.com as the root, and Google's discovery document faked. */
    public static function deployment(): void
    {
        multiTenantDeployment(self::HOST);
        config([
            'cbox-id.environments.base_domains' => [self::HOST],
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
    }

    /** A platform operator, signed in at the root with a confirmed address. */
    public static function operatorSignedIn(): string
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
    public static function turnOn(): Connection
    {
        test()->from('https://'.self::HOST.'/admin/social-sign-in?provider=google')
            ->post('https://'.self::HOST.'/admin/social-sign-in', [
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
    public static function signedOutBrowser(): void
    {
        app()->forgetScopedInstances();
        session()->flush();
    }

    /** Signed out, but the same browser: the session's other contents survive. */
    public static function signOut(): void
    {
        app()->forgetScopedInstances();
        session()->forget([PlatformAuth::SESSION_KEY, PlatformAuth::ORG_KEY, PlatformAuth::ACCOUNTS_KEY, PlatformAuth::ACTIVE_KEY]);
    }

    /**
     * Click Google on the root's sign-in page and come back from Google signed in as
     * $email — returning the callback's response.
     *
     * @param  array<string, mixed>  $claims  overrides for the id_token (`email_verified`, `sub`, …)
     * @param  array<string, string>|null  $answer  what Google sends back instead of a code (`['error' => 'access_denied']`)
     */
    public static function signIn(string $email, array $claims = [], ?string $tamperState = null, ?array $answer = null, string $host = self::HOST): TestResponse
    {
        $login = test()->get('https://'.$host.'/login')->assertOk();
        $google = collect($login->inertiaProps('providers'))->firstWhere('provider', 'google');

        expect($google)->not->toBeNull($host.'\'s sign-in page offers no Google button');

        return self::through((string) $google['url'], $email, $claims, $tamperState, $answer);
    }

    /**
     * Follow a Google start URL (a sign-in button, or My account's Connect) to Google and
     * back as $email.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, string>|null  $answer
     */
    public static function through(string $startUrl, string $email, array $claims = [], ?string $tamperState = null, ?array $answer = null): TestResponse
    {
        $start = test()->get($startUrl);

        // My account's Connect hops through the sign-in button's own redirect first.
        if (str_contains((string) $start->headers->get('Location'), '/sso/oidc/') && str_ends_with((string) $start->headers->get('Location'), '/redirect')) {
            $start = test()->get((string) $start->headers->get('Location'));
        }

        $authorize = (string) $start->headers->get('Location');
        parse_str((string) parse_url($authorize, PHP_URL_QUERY), $query);

        // ONE redirect URI, on the host the button is on: the address its administrator
        // registered with Google — the root's for the platform's Google, a tenant's for its own.
        $host = (string) parse_url($startUrl, PHP_URL_HOST);

        expect($authorize)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
            ->and((string) $query['redirect_uri'])->toStartWith('https://'.($host === '' ? self::HOST : $host).'/sso/oidc/')
            ->and((string) $query['redirect_uri'])->toEndWith('/callback');

        $state = $tamperState ?? (string) $query['state'];

        if ($answer !== null) {
            return test()->get((string) $query['redirect_uri'].'?'.http_build_query([...$answer, 'state' => $state]));
        }

        self::fakeTokenEndpoint($email, (string) $query['nonce'], $claims);

        return test()->get((string) $query['redirect_uri'].'?'.http_build_query(['code' => 'the-code', 'state' => $state]));
    }

    /** Follow the root's open-environment door into a tenant's console, by its signed POST. */
    public static function handOff(string $openUrl, string $tenantHost): TestResponse
    {
        $door = test()->get($openUrl)->assertOk();
        $fields = $door->viewData('fields');
        $action = (string) $door->viewData('action');

        // The credential travels in a form body to the tenant's own host — never in a URL.
        expect($action)->toBe('https://'.$tenantHost.'/admin/handoff')
            ->and($openUrl)->not->toContain('token=');

        return test()->post($action, is_array($fields) ? $fields : []);
    }

    /** The Google subject a test address signs in as, unless a test names another. */
    public static function subjectFor(string $email): string
    {
        return 'google|'.md5($email);
    }

    /**
     * Google's token endpoint answers with an id_token for $email, signed by a key its JWKS
     * publishes.
     *
     * ONE KEY PER PROCESS, and the stubs read the CURRENT token rather than capturing one:
     * `Http::fake()` keeps every stub registered in a test and the first match wins, and the
     * validator caches the key set — so a second sign-in in the same test that minted a new
     * key and a new stub would be answered with the first one's token, or verified against
     * the first one's key, and fail its nonce or its signature for reasons that are the
     * fixture's and not the product's.
     *
     * @param  array<string, mixed>  $claims
     */
    private static function fakeTokenEndpoint(string $email, string $nonce, array $claims): void
    {
        if (self::$pem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            $details = openssl_pkey_get_details($key);
            $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

            self::$pem = $pem;
            self::$jwks = [[
                'kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256',
                'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
            ]];
        }

        self::$idToken = JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'google-client-id',
            'sub' => self::subjectFor($email),
            'email' => $email,
            'email_verified' => true,
            'name' => 'Google Person',
            'nonce' => $nonce,
            'iat' => time(),
            'exp' => time() + 300,
            ...$claims,
        ], self::$pem, 'RS256', 'k1');

        Http::fake([
            'oauth2.googleapis.com/token' => static fn () => Http::response(['id_token' => self::$idToken]),
            'www.googleapis.com/oauth2/v3/certs' => static fn () => Http::response(['keys' => self::$jwks]),
        ]);
    }
}
