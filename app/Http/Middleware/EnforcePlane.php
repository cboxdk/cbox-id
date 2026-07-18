<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hard plane isolation by HOST. The two interactive planes never share a host:
 *
 *  - `plane:account` — the ACCOUNT/buyer plane (cboxid.com): sign up / manage the
 *    account, its environments, billing and keys. Served ONLY on the platform-root
 *    (is_default) host.
 *  - `plane:subject` — the SUBJECT/tenant plane (a tenant's own {slug}.{base}
 *    subdomain): its sign-in and org-admin console. Served ONLY on a NON-root host.
 *
 * A request for the wrong plane on a host is refused with 404 — it simply does not
 * exist there. This is what makes the planes non-overridable: a subject session can
 * carry no weight on the account host because the subject surface is absent, and an
 * account session carries none on a tenant host. Deny-by-default: if the environment
 * can't be resolved at all, every plane 404s rather than leaking the wrong one.
 */
final class EnforcePlane
{
    public function __construct(
        private readonly EnvironmentContext $environments,
        private readonly EnvironmentResolver $resolver,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $plane): Response
    {
        // Single-tenant / self-hosted (no `base_domains`) is ONE host serving the whole
        // IdP — there is no account/subject host split, so the bulkheads don't apply and
        // every plane is served. Only the multi-tenant SaaS shape (base_domains set,
        // e.g. cboxid.com) has separate account-root and tenant-subdomain hosts.
        if (! $this->multiTenant()) {
            return $next($request);
        }

        $current = $this->environments->current()?->environmentKey();
        $default = $this->platformRootKey();
        $onRoot = $current !== null && $default !== null && $current === $default;

        $allowed = match ($plane) {
            'account' => $onRoot,
            'subject' => ! $onRoot && $current !== null,
            default => false,
        };

        abort_unless($allowed, 404);

        return $next($request);
    }

    /**
     * The multi-tenant SaaS shape — subdomain→environment routing is configured, so
     * the account plane and the tenant planes live on separate hosts. Empty
     * `base_domains` means a single-tenant / self-hosted deployment (one forced IdP).
     */
    private function multiTenant(): bool
    {
        $bases = config('cbox-id.environments.base_domains', []);

        return is_array($bases) && $bases !== [];
    }

    /**
     * The platform-root environment key — the account plane's host. Resolved the SAME
     * way as the SetEnvironment middleware: the configured default
     * (`CBOX_ID_ENVIRONMENT_DEFAULT`) wins, else the database `is_default` environment.
     * Keeping them in lock-step is what makes "is this the account-root host?" agree
     * with which environment the request actually resolved to.
     */
    private function platformRootKey(): ?string
    {
        $configured = config('cbox-id.environments.default');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->resolver->defaultEnvironment()?->environmentKey();
    }
}
