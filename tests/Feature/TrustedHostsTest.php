<?php

declare(strict_types=1);

use App\Http\Middleware\TrustHostsExceptHealth;
use App\Platform\TrustedHosts;
use Illuminate\Http\Request;

/**
 * The Host headers this deployment answers on.
 *
 * Asserted against the DERIVATION rather than through the middleware, deliberately:
 * Laravel's `TrustHosts` is inert in the `local` environment AND under
 * `runningUnitTests()`, so a test that drives it proves nothing at all. That is precisely
 * how registering it nearly took production down — the half that can be wrong is the
 * pattern list, and the suite could not see it.
 */
function trusts(string $host): bool
{
    foreach (app(TrustedHosts::class)->patterns() as $pattern) {
        if (preg_match('{'.$pattern.'}i', $host) === 1) {
            return true;
        }
    }

    return false;
}

beforeEach(function (): void {
    config([
        'cbox-id.tenancy.multi_tenant' => true,
        'cbox-id.tenancy.account_host' => 'cboxid.com',
        'cbox-id.environments.base_domains' => ['cboxid.com'],
    ]);
});

it('trusts the names this deployment actually serves', function (): void {
    expect(trusts('cboxid.com'))->toBeTrue()
        ->and(trusts('acme.cboxid.com'))->toBeTrue();
})->group('security');

/**
 * The one that mattered, and the mechanism that answers it.
 *
 * Kubernetes sends the POD IP as `Host` on an `httpGet` probe unless the manifest sets a
 * header, and `TrustHosts` runs first in the global stack, ahead of routing. Every pattern
 * derived above is a public name, so all three probes would have answered 400 and
 * crash-looped every pod on the first rollout.
 *
 * The first attempt trusted loopback and the private IPv4 ranges. That enumerated the
 * shapes an internal caller might arrive as, and the enumeration was incomplete the moment
 * it was written — an IPv6 pod address on a dual-stack cluster, a link-local probe, a bare
 * Service name with no dots. Each miss is the same outage, and it widened Host trust for
 * every request to fix two paths.
 *
 * The promise `docs/operations/deployment.md` makes is about the PATH. So the health
 * endpoints are exempt from the check, no additional host is trusted for anything else,
 * and no cluster's addressing can make it wrong.
 */
it('exempts the health endpoints, so a probe cannot crash-loop the deployment', function (): void {
    $middleware = app(TrustHostsExceptHealth::class);

    // Asserted on the DECISION, not on a 200. Laravel's TrustHosts is inert under
    // `runningUnitTests()` and in `local`, so driving handle() and seeing a 200 proves
    // nothing at all — which is precisely why this bug reached a tagged release with a
    // green suite.
    foreach (['10.42.0.17', '[fd00:10:244::a]', '169.254.10.5', 'cbox-id-web-7d8', 'localhost'] as $host) {
        foreach (['up', 'health/ready'] as $path) {
            expect($middleware->exempt(Request::create('http://'.$host.'/'.$path)))
                ->toBeTrue("a probe on {$host}/{$path} would 400 and crash-loop every pod");
        }
    }

    // And nothing else is exempt — an exemption from a security control is enumerable.
    foreach (['workspace/login', 'oauth/token', 'platform', ''] as $path) {
        expect($middleware->exempt(Request::create('http://evil.example/'.$path)))
            ->toBeFalse("{$path} was handed a bypass it was never meant to have");
    }
})->group('security');

/**
 * And the refusals the whole thing exists for. Note `cboxid.com.evil.example`: Symfony
 * matches these patterns UNANCHORED, so an unquoted, unanchored `cboxid.com` would trust
 * the very header being fenced.
 */
it('refuses a host this deployment does not serve', function (): void {
    expect(trusts('evil.example'))->toBeFalse()
        ->and(trusts('cboxid.com.evil.example'))->toBeFalse()
        ->and(trusts('cboxid-com.evil.example'))->toBeFalse()
        // A PUBLIC address in the probe position is a misconfiguration and should be heard
        // as a 400, not silently trusted — which is why the ranges are named, not `.*`.
        ->and(trusts('8.8.8.8'))->toBeFalse()
        ->and(trusts('172.32.0.1'))->toBeFalse();
})->group('security');
