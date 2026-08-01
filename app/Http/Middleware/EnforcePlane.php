<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\PlaneResolver;
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
        private readonly PlaneResolver $planes,
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
        if (! $this->planes->isMultiTenant()) {
            // …but an unknown plane name is still refused, in every shape.
            abort_unless(in_array($plane, ['account', 'subject', 'operator'], true), 404);

            return $next($request);
        }

        $allowed = match ($plane) {
            'account' => $this->planes->onAccountPlane(),
            'subject' => $this->planes->onSubjectPlane(),
            // The staff console. It shipped with no bulkhead at all, so the Cbox
            // operator sign-in was served on every tenant subdomain AND on every
            // customer-controlled brand domain (whitelabel writes environments.domain,
            // which is what host resolution keys on). No privilege was granted there —
            // the operator session is separate and AuthenticateOperator guards the rest
            // — but a staff login form on a customer's own domain is a phishing surface
            // and an unnecessary disclosure. It belongs on the platform root, with the
            // account plane.
            'operator' => $this->planes->onAccountPlane(),
            default => false,
        };

        abort_unless($allowed, 404);

        return $next($request);
    }
}
