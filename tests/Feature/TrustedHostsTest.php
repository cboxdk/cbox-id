<?php

declare(strict_types=1);

use App\Platform\TrustedHosts;

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
 * The one that mattered.
 *
 * Kubernetes sends the POD IP as `Host` on an `httpGet` probe unless the manifest sets a
 * header, and `apps/id/deployment.yaml`'s three probes do not. Every other pattern is a
 * public name, so the liveness probe would have answered 400, the pod would have been
 * killed, and its replacement would have done the same — a total outage on the first
 * rollout, caused by the fix for host-header poisoning.
 */
it('trusts the addresses a container reaches itself by, so health probes survive', function (): void {
    expect(trusts('10.42.0.17'))->toBeTrue('a Kubernetes liveness probe would 400 and crash-loop every pod')
        ->and(trusts('localhost'))->toBeTrue()
        ->and(trusts('127.0.0.1'))->toBeTrue()
        ->and(trusts('192.168.1.10'))->toBeTrue()
        ->and(trusts('172.20.0.5'))->toBeTrue();
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
