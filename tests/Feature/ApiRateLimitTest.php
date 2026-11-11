<?php

declare(strict_types=1);

use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);

    // A budget small enough to exhaust in a test. The IP backstop is 10x this, so it
    // does not bind here — that is the point of the separation.
    config(['api.rate_limits.environment' => 3]);
});

if (! function_exists('throttleKey')) {
    /** @param  list<EnvironmentApiScope>  $scopes */
    function throttleKey(string $name, array $scopes = [EnvironmentApiScope::OrganizationsRead]): string
    {
        return app(EnvironmentApiKeys::class)->issue(
            'env_test',
            $name,
            array_map(fn (EnvironmentApiScope $s): string => $s->value, $scopes),
        )->plaintext;
    }
}

/**
 * Rate limiting is keyed on the CREDENTIAL, not the client IP.
 *
 * The routes carried a bare `throttle:240,1` — Laravel's default IP key — and the repo
 * contained no `RateLimiter::for()` at all. So every customer whose CI egressed through
 * one NAT (every hosted runner, every corporate proxy) shared a single bucket, and one
 * noisy tenant throttled all the others. That is precisely the wrong failure mode for
 * the Terraform/CLI/SDK traffic this API is about to carry.
 */
it('registers a named limiter for every API plane', function (): void {
    // Guards against the silent-failure mode of named limiters: `throttle:api-organization`
    // with no registered limiter is parsed as a NUMERIC limit of zero attempts, which
    // would 429 every single request.
    foreach (['api-organization', 'api-environment', 'api-vault', 'api-apps'] as $limiter) {
        expect(RateLimiter::limiter($limiter))->not->toBeNull("The `{$limiter}` limiter is not registered.");
    }
});

it('gives each API key its own bucket, even from the same IP', function (): void {
    $noisy = throttleKey('noisy tenant');
    $quiet = throttleKey('quiet tenant');

    // Both keys call from 127.0.0.1 — the shared-NAT case, exactly.
    foreach (range(1, 3) as $ignored) {
        $this->withToken($noisy)->getJson('/api/v1/organizations')->assertOk();
    }

    $this->withToken($noisy)->getJson('/api/v1/organizations')->assertStatus(429);

    // With the old IP-keyed throttle this was a 429 too: the noisy tenant had already
    // spent the shared bucket. The quiet tenant's allowance is its own.
    $this->withToken($quiet)->getJson('/api/v1/organizations')->assertOk();
});

it('returns the documented envelope and a Retry-After when a key is throttled', function (): void {
    $key = throttleKey('busy');

    foreach (range(1, 3) as $ignored) {
        $this->withToken($key)->getJson('/api/v1/organizations')->assertOk();
    }

    $this->withToken($key)->getJson('/api/v1/organizations')
        ->assertStatus(429)
        // Laravel's own 429 body is a bare `{"message":"Too Many Attempts."}` — no
        // `error` code for a client to branch on, and no way to tell it from a 500.
        ->assertJsonPath('error', 'rate_limited')
        ->assertJsonStructure(['error', 'message'])
        // The client cannot back off correctly without these — the throttle sets them
        // as integers, so anything normalising the exception's headers has to keep
        // non-string values rather than quietly dropping them.
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '3');
});

it('falls back to the client IP when the request presents no credential', function (): void {
    // Unauthenticated traffic must still be metered — otherwise moving the throttle
    // behind authentication would leave the 401 path completely unbounded.
    foreach (range(1, 3) as $ignored) {
        $this->getJson('/api/v1/organizations')->assertUnauthorized();
    }

    $this->getJson('/api/v1/organizations')->assertStatus(429);
});

it('bounds a flood of distinct bogus tokens with the per-IP backstop', function (): void {
    // A per-credential bucket alone is escapable: a fresh bogus token would mint a fresh
    // bucket every time. The IP ceiling (10x the plane budget = 30 here) closes it.
    $throttled = false;

    foreach (range(1, 40) as $n) {
        $status = $this->withToken('cbid_env_bogus-'.$n)->getJson('/api/v1/organizations')->getStatusCode();

        if ($status === 429) {
            $throttled = true;
            break;
        }
    }

    expect($throttled)->toBeTrue('A flood of distinct invalid tokens escaped rate limiting entirely.');
});
