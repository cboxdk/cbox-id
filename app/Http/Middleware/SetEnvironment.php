<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\OperatorAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\Environment as EnvironmentContract;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Models\Environment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the current environment for every web request.
 *
 * For an END USER the request HOST decides the plane — an exact custom-domain
 * match, or a base-domain subdomain slug (via the package's
 * {@see EnvironmentResolver}). This is what stops a user on env B's domain from
 * being authenticated against env A just because A was created first.
 *
 * For an authenticated OPERATOR the console honours their explicitly selected
 * plane (held under {@see OperatorAuth::ENV_KEY}, distinct from any end-user key)
 * ahead of the host, so targeting a plane never depends on which domain the
 * console is served from. When none exist yet (fresh install) a bootstrap default
 * keeps the console — including the create-environment screen — renderable.
 */
final class SetEnvironment
{
    public function __construct(
        private readonly EnvironmentContext $context,
        private readonly EnvironmentResolver $resolver,
        private readonly OperatorAuth $operators,
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
        // Operators explicitly target a plane; their dedicated selection wins over
        // anything host-derived so the console never jumps planes under them.
        if ($this->operators->check()) {
            $slug = $request->session()->get(OperatorAuth::ENV_KEY);
            $selected = is_string($slug) && $slug !== ''
                ? Environment::query()->where('slug', $slug)->first()
                : null;

            return $selected ?? $this->resolver->defaultEnvironment();
        }

        // End users: the request host maps to the plane (custom domain or
        // base-domain subdomain). A spoofed Host that maps to nothing resolves to
        // null, never an attacker-chosen plane.
        $byHost = $this->resolver->resolveForHost($request->getHost());

        if ($byHost !== null) {
            return $byHost;
        }

        // Unmapped host → the PLATFORM-ROOT (is_default) environment — Cbox's own,
        // never a customer's. This closes the fail-open bug where an unrecognized or
        // spoofed Host fell through to the OLDEST environment (which could be any
        // customer's live IdP): the apex/console/signup keep working, but no
        // unmapped host can ever be mapped to a customer tenant. A customer plane is
        // reachable ONLY via its own custom domain or {slug}.{base_domain} subdomain.
        return $this->resolver->defaultEnvironment();
    }
}
