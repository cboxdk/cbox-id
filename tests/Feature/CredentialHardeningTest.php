<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * "Rotate secret" on a private_key_jwt client did not rotate anything — it CREATED a
 * credential the client was deliberately built without.
 *
 * Such a client is Confidential with a JWKS and no secret, by construction: the registry
 * mints a secret only when no JWKS is registered, because a client authenticates EITHER
 * by a shared secret OR by signing assertions, never both. The handler guarded on type
 * alone, so a click added a bearer secret, and ClientAuthenticator then accepts
 * client_id + secret whenever no assertion is presented. A one-click downgrade of an
 * authentication model, presented as routine hygiene — and the button's own copy ("the
 * current one stops working") was false, because there was no current one.
 */
it('refuses to mint a secret for a client that signs its own assertions', function (): void {
    crudSetup();

    $client = app(ClientRegistry::class)->register(new NewClient(
        'Assertion App',
        type: ClientType::Confidential,
        redirectUris: ['https://app.test/cb'],
        jwks: ['keys' => [['kty' => 'RSA', 'kid' => 'a', 'n' => 'x', 'e' => 'AQAB']]],
    ))->client;

    expect($client->secret_hash)->toBeNull();

    confirmEnvironmentStepUp();

    test()->from(route('environment.clients.show', $client->id))
        ->post(route('environment.clients.rotate', $client->id));

    expect($client->fresh()?->secret_hash)->toBeNull('rotation gave an asymmetric-only client a bearer secret');
});

/**
 * The MFA counters on the workspace and admin planes included the source IP, so an
 * attacker holding the password rotated addresses and got a fresh bucket of five each
 * time. TOTP rolls every 30 seconds, so that is slow; recovery codes do not roll, which
 * is the half that gives way. The subject plane has always keyed on the pending member
 * alone, with a comment saying an attacker must not be able to grind it.
 *
 * Read from source: the property under test is what the key is COMPOSED of, and there is
 * no way to observe that from a single request without actually rotating addresses.
 */
it('meters MFA attempts per member, not per member and address', function (): void {
    $offenders = [];

    /*
     * THE CONTROLLERS, which is where the key is composed now — and the PATTERN follows the
     * code too. This looked for `$key = 'mfa|' … . request()->ip()`, the shape a Volt
     * component wrote; a ported controller returns the key from a method, so the old
     * expression matched nothing at all and the sweep reported a clean run over code it
     * could not see.
     */
    foreach (Finder::create()->files()->in(base_path('app/Http/Controllers'))->name('*.php') as $file) {
        $source = (string) file_get_contents((string) $file->getRealPath());

        // Every expression that composes an MFA throttle key, however it is spelled.
        preg_match_all("/'[a-z-]*mfa[a-z-]*\|'[^;]*/", $source, $matches);

        foreach ($matches[0] as $expression) {
            if (! str_contains($expression, '->ip()')) {
                continue;
            }

            /*
             * The offending shape is CONCATENATION — appending the address to the key, so
             * every new address is a new bucket. `?? $request->ip()` is a FALLBACK for when
             * there is no pending subject to key on at all, which is correct and must not
             * be flagged: a check that cannot tell those apart would send somebody to "fix"
             * the one plane that had it right.
             */
            if (! str_contains($expression, '??')) {
                $offenders[] = $file->getRelativePathname();
            }
        }
    }

    expect($offenders)->toBe([], 'MFA throttle keyed on the source IP: '.implode(', ', $offenders));
});

/**
 * The operator plane — above every tenant — had no account lockout at all. Only the
 * form's per-(email, address) cache bucket, whose own comment claimed to "throttle + lock
 * out brute force" while doing the first half alone. Both other planes have had a real
 * lockout from the start.
 *
 * It has one now by CONSTRUCTION rather than by a second implementation: the operator
 * door is the subject door, so the lockout protecting an operator's password is the one
 * protecting everyone's. This asserts that from the operator's side — that the credential
 * an operator actually signs in with is behind it — because "we deleted the weak door" is
 * only good news if the person who used it landed behind the strong one.
 */
it('locks an operator out of the password door at the policy threshold', function (): void {
    platformRootDeployment();

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(lockoutThreshold: 3, requireBreachCheck: false));

    app(PlatformOperators::class)->create('locked@platform.test', 'the-real-operator-pass', 'Op');

    $auth = app(PlatformAuth::class);
    $attempt = fn (string $password) => app(PlatformRoot::class)->run(
        fn () => $auth->attemptPassword(request(), 'locked@platform.test', $password),
    );

    foreach (range(1, 3) as $ignored) {
        $attempt('wrong-guess-entirely');
    }

    // The RIGHT password now, and it must still be refused — otherwise the lockout is
    // decoration and the attacker simply keeps going.
    expect($attempt('the-real-operator-pass')->name)->toBe('Invalid');
});
