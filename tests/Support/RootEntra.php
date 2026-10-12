<?php

declare(strict_types=1);

namespace Tests\Support;

use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\PlatformRoot;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * MICROSOFT ENTRA ID ON THE PLATFORM ROOT, faked end to end — the {@see RootGoogle} of
 * Microsoft, for the tests that hold how an Entra sign-in is treated.
 *
 * Real OpenID Connect against a faked Entra tenant: the catalogue's `microsoft` template
 * with a directory id, Entra's discovery document under that tenant's issuer, a real RS256
 * id_token carrying the application's nonce and — when a test says so — the optional
 * `xms_edov` claim, and the key set that verifies it. Entra never sends `email_verified`,
 * so the default token does not either.
 */
final class RootEntra
{
    public const TENANT = '72f988bf-86f1-41af-91ab-2d7cd011db47';

    private const ISSUER = 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0';

    private static ?string $pem = null;

    /** @var list<array<string, string>> */
    private static array $jwks = [];

    private static string $idToken = '';

    /** Entra's discovery document for the tenant, faked (call after {@see RootGoogle::deployment()}). */
    public static function discovery(): void
    {
        Http::fake([
            'login.microsoftonline.com/'.self::TENANT.'/v2.0/.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/authorize',
                'token_endpoint' => 'https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token',
                'jwks_uri' => 'https://login.microsoftonline.com/'.self::TENANT.'/discovery/v2.0/keys',
            ]),
        ]);
    }

    /** Microsoft, environment-wide, in the platform root — as an operator does it in its console. */
    public static function turnOn(): Connection
    {
        test()->from('https://'.RootGoogle::HOST.'/admin/social-sign-in?provider=microsoft')
            ->post('https://'.RootGoogle::HOST.'/admin/social-sign-in', [
                'provider' => 'microsoft',
                'clientId' => 'entra-client-id',
                'clientSecret' => 'entra-client-secret',
                'parameters' => ['directory' => self::TENANT],
                'forEnvironment' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $root = app(PlatformRoot::class)->model();

        return app(EnvironmentContext::class)->runAs($root, static fn (): Connection => Connection::query()->where('provider', 'microsoft')->sole());
    }

    /**
     * Click Microsoft on the root's sign-in page and come back signed in as $email.
     *
     * @param  array<string, mixed>  $claims  added to the id_token — `xms_edov`, typically
     */
    public static function signIn(string $email, array $claims = []): TestResponse
    {
        $login = test()->get('https://'.RootGoogle::HOST.'/login')->assertOk();
        $button = collect($login->inertiaProps('providers'))->firstWhere('provider', 'microsoft');

        expect($button)->not->toBeNull('the sign-in page offers no Microsoft button');

        $start = test()->get((string) $button['url']);

        if (str_ends_with((string) $start->headers->get('Location'), '/redirect')) {
            $start = test()->get((string) $start->headers->get('Location'));
        }

        $authorize = (string) $start->headers->get('Location');
        parse_str((string) parse_url($authorize, PHP_URL_QUERY), $query);

        expect($authorize)->toStartWith('https://login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/authorize?');

        self::fakeTokenEndpoint($email, (string) $query['nonce'], $claims);

        return test()->get((string) $query['redirect_uri'].'?'.http_build_query(['code' => 'the-code', 'state' => (string) $query['state']]));
    }

    /** @param  array<string, mixed>  $claims */
    private static function fakeTokenEndpoint(string $email, string $nonce, array $claims): void
    {
        if (self::$pem === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $pem);
            $details = openssl_pkey_get_details($key);
            $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

            self::$pem = $pem;
            self::$jwks = [[
                'kty' => 'RSA', 'kid' => 'e1', 'use' => 'sig', 'alg' => 'RS256',
                'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
            ]];
        }

        // Entra's shape: `tid`, `oid`, `preferred_username` — and no `email_verified`.
        self::$idToken = JWT::encode([
            'iss' => self::ISSUER,
            'aud' => 'entra-client-id',
            'sub' => 'entra|'.md5($email),
            'oid' => md5('oid'.$email),
            'tid' => self::TENANT,
            'email' => $email,
            'preferred_username' => $email,
            'name' => 'Entra Person',
            'nonce' => $nonce,
            'iat' => time(),
            'exp' => time() + 300,
            ...$claims,
        ], self::$pem, 'RS256', 'e1');

        Http::fake([
            'login.microsoftonline.com/'.self::TENANT.'/oauth2/v2.0/token' => static fn () => Http::response(['id_token' => self::$idToken]),
            'login.microsoftonline.com/'.self::TENANT.'/discovery/v2.0/keys' => static fn () => Http::response(['keys' => self::$jwks]),
        ]);
    }
}
