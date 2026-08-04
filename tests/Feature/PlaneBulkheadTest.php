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
    // Multi-tenant shape, STATED. It used to be implied by setting `base_domains`, which
    // worked only because nothing else stated the mode; once the flag existed, the
    // ambient value from `.env` decided these tests instead of the line below them.
    config(['cbox-id.tenancy.multi_tenant' => true]);
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

/**
 * The HOST is a parameter, because one of the four planes is a host question.
 *
 * `plane:console` asks {@see PlaneResolver::servesConsole()}, which resolves the request's
 * own Host rather than the environment context — an unmapped name resolves to the platform
 * root, so the context cannot tell `cboxid.com` from `anything.invalid`. A helper that
 * always sent `localhost` would have asserted the console away on every host and called it
 * a passing bulkhead.
 */
function passesPlane(EnforcePlane $gate, string $plane, string $host = 'localhost'): bool
{
    try {
        $gate->handle(Request::create('https://'.$host.'/'), fn () => new Response('ok'), $plane);

        return true;
    } catch (NotFoundHttpException) {
        return false;
    }
}

it('serves the account plane ONLY on the platform-root host', function (): void {
    // Root host: current env IS the default (is_default) env.
    $root = planeGate('env_prod', 'env_prod');
    $tenant = planeGate('env_tenant_a', 'env_prod');

    expect(passesPlane($root, 'account'))->toBeTrue()
        ->and(passesPlane($tenant, 'account'))->toBeFalse(); // never on a tenant host
});

/**
 * The console is served EVERYWHERE, and the platform root is the case worth pinning.
 *
 * It was `plane:subject` — "every host except the root" — so `cboxid.com/login` and
 * `cboxid.com/dashboard` were both 404. The root is a tenant: its subjects sign in and
 * administer their organizations exactly as any other tenant's do. What it withholds is
 * the ISSUER surface, which is now a different question and asserted as one below.
 */
it('serves the console plane on the platform root as well as on a tenant host', function (): void {
    $tenant = planeGate('env_tenant_a', 'env_prod');

    expect(passesPlane($tenant, 'console'))->toBeTrue();

    // The root, reached by its own name. `hostResolves` is null on purpose — a root
    // environment provisioned without a `domain` row resolves by host to nothing, which is
    // the shape production has, so the apex has to be matched by name (base_domains
    // derives accountHost() = cboxid.com).
    $root = planeGate('env_prod', 'env_prod');

    expect(passesPlane($root, 'console', 'cboxid.com'))
        ->toBeTrue('the platform root has no sign-in door');
});

/**
 * …but not under any OTHER name pointed at the deployment.
 *
 * `SetEnvironment` answers an unmapped host with the platform root, deliberately, and
 * `TrustHosts` admits every wildcard name under `base_domains`. So the CONTEXT is the root
 * environment for `nope.cboxid.com` exactly as it is for `cboxid.com`, and a console gate
 * that read the context would have served a sign-in form on all of them. Asking the host
 * is what makes the difference, and this is the assertion that proves it was asked.
 */
it('refuses the console plane on a name that is not the platform root', function (): void {
    $unmapped = planeGate('env_prod', 'env_prod');

    expect(passesPlane($unmapped, 'console', 'nope.cboxid.com'))->toBeFalse()
        ->and(passesPlane($unmapped, 'console', 'anything.invalid'))->toBeFalse();
});

/**
 * The half of the old `plane:subject` that still holds: the platform root is an identity
 * provider for nobody, so the issuer surface and the environment-admin door are absent
 * there. Separating the console from these two is the whole of this change.
 */
it('withholds the issuer surface and the environment console from the platform root', function (): void {
    $root = planeGate('env_prod', 'env_prod');
    $tenant = planeGate('env_tenant_a', 'env_prod');

    expect(passesPlane($root, 'issuer', 'cboxid.com'))->toBeFalse()
        ->and(passesPlane($root, 'environment', 'cboxid.com'))->toBeFalse()
        ->and(passesPlane($tenant, 'issuer'))->toBeTrue()
        ->and(passesPlane($tenant, 'environment'))->toBeTrue();
});

it('denies every plane when no environment resolves (deny-by-default)', function (): void {
    $none = planeGate(null, 'env_prod');

    expect(passesPlane($none, 'account'))->toBeFalse()
        ->and(passesPlane($none, 'console'))->toBeFalse()
        ->and(passesPlane($none, 'issuer'))->toBeFalse()
        ->and(passesPlane($none, 'environment'))->toBeFalse();
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

/**
 * The other half of that bulkhead, and the half that shipped broken to production.
 *
 * A root environment provisioned without its own `domain` row resolves by host to
 * nothing, so it reaches the fallback — which asked `platformRootHosts()`, a config key
 * with no env binding that no deployment sets. `accountHost()`, meanwhile, resolves
 * through a three-step chain that lands in every real shape. The two disagreed, and the
 * operator console is the SAME origin as the account console by design, so:
 *
 *     GET https://cboxid.com/login   200
 *     GET https://cboxid.com/operator/login    404
 *
 * The staff console had no door at all on the live deployment. It went unnoticed because
 * a 404 from a plane bulkhead is indistinguishable from a route that was never there.
 */
it('serves the operator plane on the account host when the root has no domain row', function (): void {
    // base_domains derives accountHost() = cboxid.com; the host resolves to nothing.
    $gate = planeGate(null, 'env_root');

    $request = Request::create('https://cboxid.com/operator/login');

    $served = true;

    try {
        $gate->handle($request, fn () => new Response('ok'), 'operator');
    } catch (NotFoundHttpException) {
        $served = false;
    }

    expect($served)->toBeTrue('the staff console has no door on the account host');
});

it('does NOT split planes in a single-tenant / self-hosted deployment (no base_domains)', function (): void {
    // Single-tenant: one host serves the whole IdP — every plane is allowed, so the
    // subject console is never 404'd just because the lone env is also the default.
    config(['cbox-id.tenancy.multi_tenant' => false]);
    config(['cbox-id.environments.base_domains' => []]);

    $ctx = Mockery::mock(EnvironmentContext::class);
    $ctx->shouldReceive('current')->andReturn(GenericEnvironment::of('the_only_env'));
    $resolver = Mockery::mock(EnvironmentResolver::class);
    $resolver->shouldReceive('defaultEnvironment')->andReturn(GenericEnvironment::of('the_only_env'));
    $gate = new EnforcePlane(new PlaneResolver($ctx, $resolver));

    expect(passesPlane($gate, 'console'))->toBeTrue()
        ->and(passesPlane($gate, 'issuer'))->toBeTrue()
        ->and(passesPlane($gate, 'environment'))->toBeTrue()
        ->and(passesPlane($gate, 'account'))->toBeTrue();
});

/**
 * The plane names the single-tenant branch admits and the names the multi-tenant match
 * arms answer are ONE list now, so they cannot drift — but a rename is exactly the moment
 * a route can be left holding a name that no longer exists, and a plane gate that admits
 * an unknown name is a gate that is not there.
 */
it('refuses a plane name that no longer exists, in both shapes', function (): void {
    $multi = planeGate('env_tenant_a', 'env_prod');

    expect(passesPlane($multi, 'subject'))->toBeFalse('`plane:subject` was split; the name must not still admit');

    config(['cbox-id.tenancy.multi_tenant' => false]);

    $ctx = Mockery::mock(EnvironmentContext::class);
    $ctx->shouldReceive('current')->andReturn(GenericEnvironment::of('the_only_env'));
    $resolver = Mockery::mock(EnvironmentResolver::class);
    $resolver->shouldReceive('defaultEnvironment')->andReturn(GenericEnvironment::of('the_only_env'));

    expect(passesPlane(new EnforcePlane(new PlaneResolver($ctx, $resolver)), 'subject'))->toBeFalse();
});
