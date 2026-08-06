<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Cbox\Id\Kernel\Tenancy\Contracts\Environment as EnvironmentContract;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the current environment for every web request.
 *
 * The request HOST decides the plane — an exact custom-domain match, or a
 * base-domain subdomain slug (via the package's {@see EnvironmentResolver}). This is
 * what stops a user on env B's domain from being authenticated against env A just
 * because A was created first. When no environment exists yet (fresh install) a
 * bootstrap default keeps the console — including the create-environment screen —
 * renderable.
 *
 * The HOST, and nothing else. This used to consult the operator's selected plane
 * first, which was safe only while an operator was a separate credential store. An
 * operator is a subject now, `auth_sessions` is environment-owned and hard-scoped,
 * and their subject lives in the platform root — so a tenant plane pinned HERE, ahead
 * of {@see Authenticate}, hides the operator's own session from the query that
 * resolves it. The selection is applied after authentication instead, by
 * {@see TargetEnvironment} on the operator console's own routes.
 */
final class SetEnvironment
{
    public function __construct(
        private readonly EnvironmentContext $context,
        private readonly EnvironmentResolver $resolver,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $environment = $this->resolve($request);

        if ($environment !== null) {
            $this->context->set($environment);

            return $next($request);
        }

        // No environment provisioned yet — a bootstrap default.
        $default = config('cbox-id.environments.default');
        $this->context->set(GenericEnvironment::of(is_string($default) && $default !== '' ? $default : 'default'));

        return $next($request);
    }

    private function resolve(Request $request): ?EnvironmentContract
    {
        // The request host maps to the plane (custom domain or base-domain
        // subdomain). A spoofed Host that maps to nothing resolves to null, never an
        // attacker-chosen plane.
        $byHost = $this->resolver->resolveForHost($request->getHost());

        if ($byHost !== null) {
            return $byHost;
        }

        // Unmapped host → the PLATFORM-ROOT (is_default) environment — Cbox's own,
        // never a customer's. This closes the fail-open bug where an unrecognized or
        // spoofed Host fell through to the OLDEST environment (which could be any
        // customer's live IdP): the apex/console/signup keep working, but no unmapped
        // host can ever be mapped to a customer tenant. A customer plane is reachable
        // ONLY via its own custom domain or {slug}.{base_domain} subdomain.
        //
        // NOTE (review R7): rejecting an ARBITRARY host outright (host-confusion
        // hardening) is deployment-specific — the console/apex/signup are served on
        // hosts that vary per deployment — so it belongs at the ingress (route only
        // known hosts to the app), not here where it would 404 legitimate surfaces.
        return $this->resolver->defaultEnvironment();
    }
}
