<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\PlaneResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A surface that only exists when the deployment is multi-tenant.
 *
 * The environment-admin console is the case. It is gated on an ACCOUNT member
 * administering one of their account's environments — and a single-tenant install has one
 * environment, which is the platform root, which belongs to no account.
 * `accessibleEnvironmentIds()` resolves ownership through the project, so the
 * platform root can never appear in anybody's list.
 *
 * So on that shape the console could be opened by nobody, ever, while still rendering a
 * sign-in form, accepting correct credentials, and refusing them with a message that
 * blamed the credentials. A door that cannot open should not be a door.
 *
 * 404 rather than 403: whether this deployment runs multi-tenant is not something an
 * unauthenticated caller needs to learn from a status code.
 */
final class RequireMultiTenant
{
    public function __construct(private readonly PlaneResolver $planes) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->planes->isMultiTenant(), 404);

        return $next($request);
    }
}
