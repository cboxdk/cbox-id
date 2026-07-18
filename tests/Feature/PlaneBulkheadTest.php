<?php

declare(strict_types=1);

use App\Http\Middleware\EnforcePlane;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Build the middleware with a fixed current + default environment (multi-tenant SaaS). */
function planeGate(?string $current, ?string $default): EnforcePlane
{
    // Multi-tenant shape: base_domains set → the bulkheads engage.
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    // The platform-root env is resolved via the config default first (like SetEnvironment).
    config(['cbox-id.environments.default' => $default ?? '']);

    $ctx = Mockery::mock(EnvironmentContext::class);
    $ctx->shouldReceive('current')->andReturn($current !== null ? GenericEnvironment::of($current) : null);

    $resolver = Mockery::mock(EnvironmentResolver::class);
    $resolver->shouldReceive('defaultEnvironment')->andReturn($default !== null ? GenericEnvironment::of($default) : null);

    return new EnforcePlane($ctx, $resolver);
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
    expect(passesPlane(planeGate('env_prod', 'env_prod'), 'operator'))->toBeFalse();
});

it('does NOT split planes in a single-tenant / self-hosted deployment (no base_domains)', function (): void {
    // Single-tenant: one host serves the whole IdP — every plane is allowed, so the
    // subject console is never 404'd just because the lone env is also the default.
    config(['cbox-id.environments.base_domains' => []]);

    $ctx = Mockery::mock(EnvironmentContext::class);
    $ctx->shouldReceive('current')->andReturn(GenericEnvironment::of('the_only_env'));
    $resolver = Mockery::mock(EnvironmentResolver::class);
    $resolver->shouldReceive('defaultEnvironment')->andReturn(GenericEnvironment::of('the_only_env'));
    $gate = new EnforcePlane($ctx, $resolver);

    expect(passesPlane($gate, 'subject'))->toBeTrue()
        ->and(passesPlane($gate, 'account'))->toBeTrue();
});
