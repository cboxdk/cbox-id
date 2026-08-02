<?php

declare(strict_types=1);

use App\Http\Middleware\EnforcePlane;
use App\Platform\PlaneResolver;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Build the middleware with a fixed current + default environment (multi-tenant SaaS). */
function planeGate(?string $current, ?string $default, ?string $hostResolves = null): EnforcePlane
{
    // Multi-tenant shape: base_domains set → the bulkheads engage.
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    // The platform-root env is resolved via the config default first (like SetEnvironment).
    config(['cbox-id.environments.default' => $default ?? '']);

    $ctx = Mockery::mock(EnvironmentContext::class);
    $ctx->shouldReceive('current')->andReturn($current !== null ? GenericEnvironment::of($current) : null);

    $resolver = Mockery::mock(EnvironmentResolver::class);
    $resolver->shouldReceive('defaultEnvironment')->andReturn($default !== null ? GenericEnvironment::of($default) : null);
    // The operator gate asks the HOST, not the context. An unmapped host resolves to
    // null and falls through to the default, exactly as SetEnvironment does.
    $resolver->shouldReceive('resolveForHost')
        ->andReturn($hostResolves !== null ? GenericEnvironment::of($hostResolves) : null);

    return new EnforcePlane(new PlaneResolver($ctx, $resolver));
}

function passesPlane(EnforcePlane $gate, string $plane): bool
{
    try {
        $gate->handle(Request::create('/'), fn () => new Response('ok'), $plane);

        return true;
    } catch (NotFoundHttpException) {
        return false;
    }
}

it('serves the account plane ONLY on the platform-root host', function (): void {
    // Root host: current env IS the default (is_default) env.
    $root = planeGate('env_prod', 'env_prod');

    expect(passesPlane($root, 'account'))->toBeTrue()
        ->and(passesPlane($root, 'subject'))->toBeFalse(); // no subject surface on the account door
});

it('serves the subject/tenant plane ONLY on a tenant subdomain host', function (): void {
    // Subdomain host: current env is a tenant env, NOT the default.
    $tenant = planeGate('env_tenant_a', 'env_prod');

    expect(passesPlane($tenant, 'subject'))->toBeTrue()
        ->and(passesPlane($tenant, 'account'))->toBeFalse(); // account plane never on a tenant host
});

it('denies BOTH planes when no environment resolves (deny-by-default)', function (): void {
    $none = planeGate(null, 'env_prod');

    expect(passesPlane($none, 'account'))->toBeFalse()
        ->and(passesPlane($none, 'subject'))->toBeFalse();
});

it('rejects an unknown plane name', function (): void {
    expect(passesPlane(planeGate('env_prod', 'env_prod'), 'not-a-plane'))->toBeFalse();
});

/**
 * The staff console is the third interactive plane, and shipped with no bulkhead: the
 * operator sign-in was served on every tenant subdomain and on every customer-controlled
 * brand domain. No privilege followed — the operator session is separate — but a Cbox
 * staff login form on a customer's own domain is a phishing surface. It belongs on the
 * platform root, where account credentials are already entered.
 *
 * This test previously asserted the opposite: that 'operator' was an UNKNOWN plane. It
 * encoded the gap rather than catching it.
 */
it('serves the operator plane on the platform-root host only', function (): void {
    $root = planeGate('env_root', 'env_root', hostResolves: 'env_root');
    $tenant = planeGate('env_tenant', 'env_root', hostResolves: 'env_tenant');

    expect(passesPlane($root, 'operator'))->toBeTrue()
        ->and(passesPlane($tenant, 'operator'))->toBeFalse();
});

/**
 * And it keeps serving it after the operator picks a different environment.
 *
 * `SetEnvironment` hands an authenticated operator their PINNED environment rather than
 * the host-resolved one, deliberately, so the console does not jump planes under them.
 * The gate used to read that same context — so the moment a staff member used the
 * environment switcher in the operator layout, present on every page, the context was no
 * longer the platform root and every `/operator/*` route 404'd. Including
 * `POST /operator/logout` and `GET /operator/login`: the console locked itself and the
 * only way out was clearing the session cookie by hand. Creating an environment and
 * jumping to an organization both pin as well, so those flows were broken end to end.
 */
it('keeps serving the operator plane after the operator switches environment', function (): void {
    // Pinned to a tenant environment, still on the platform-root host.
    $gate = planeGate('env_tenant', 'env_root', hostResolves: 'env_root');

    expect(passesPlane($gate, 'operator'))
        ->toBeTrue('switching environment locked the operator out of the staff console');
});

/**
 * An unmapped Host must not reach the staff console.
 *
 * This case existed, and I rewrote it away while making the gate a host question:
 * `planeGate(null, 'env_root')` became `planeGate(null, null)`, which only denies when
 * there is no default environment at all — a state a provisioned deployment never
 * reaches. Meanwhile the gate fell back to `defaultEnvironment()`, which is the same
 * value `platformRootKey()` resolves through, so it compared a value to its own source
 * and returned true for EVERY unmapped host. The docblock said it failed closed.
 *
 * `curl -H 'Host: anything.invalid'` served the Cbox staff sign-in form, on any name
 * pointed at the deployment and on every wildcard subdomain that is not a tenant — which
 * is the phishing surface the operator bulkhead was added to remove.
 */
it('denies the operator plane when the host resolves to nothing', function (): void {
    // A provisioned deployment: a default environment exists, and the host maps to
    // nothing. That combination is what used to pass.
    expect(passesPlane(planeGate(null, 'env_root'), 'operator'))->toBeFalse();

    // And with no default environment either.
    expect(passesPlane(planeGate(null, null), 'operator'))->toBeFalse();
});

it('does NOT split planes in a single-tenant / self-hosted deployment (no base_domains)', function (): void {
    // Single-tenant: one host serves the whole IdP — every plane is allowed, so the
    // subject console is never 404'd just because the lone env is also the default.
    config(['cbox-id.environments.base_domains' => []]);

    $ctx = Mockery::mock(EnvironmentContext::class);
    $ctx->shouldReceive('current')->andReturn(GenericEnvironment::of('the_only_env'));
    $resolver = Mockery::mock(EnvironmentResolver::class);
    $resolver->shouldReceive('defaultEnvironment')->andReturn(GenericEnvironment::of('the_only_env'));
    $gate = new EnforcePlane(new PlaneResolver($ctx, $resolver));

    expect(passesPlane($gate, 'subject'))->toBeTrue()
        ->and(passesPlane($gate, 'account'))->toBeTrue();
});
