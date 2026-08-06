<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\OrganizationApiContext;
use App\Platform\OrganizationCapabilities;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate a request on the organization management plane with a `Bearer cbid_org_…`
 * organization API key. Resolves the key to its organization and role, then optionally
 * enforces a capability (passed as a route-middleware parameter, e.g.
 * `organization.api:manage-members`) so a read-only key can't perform writes.
 *
 * Never resolves an environment — this plane is global. An environment-scoped credential
 * (OAuth token, M2M) is not accepted here, and vice versa: credentials never cross planes.
 */
final class AuthenticateOrganizationApi
{
    public function __construct(
        private readonly OrganizationApiKeys $keys,
        private readonly OrganizationApiContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $capability = null): Response
    {
        $token = $request->bearerToken();
        $key = $token !== null ? $this->keys->resolve($token) : null;

        if ($key === null) {
            return $this->deny('unauthorized', 'A valid organization API key is required.', 401);
        }

        if ($capability !== null && ! $this->permits($key->role, $capability)) {
            return $this->deny('forbidden', "This key's role may not {$capability}.", 403);
        }

        $this->context->set($key);

        return $next($request);
    }

    /**
     * THROUGH {@see OrganizationCapabilities}, which is the console's derivation too — this
     * was the last place in the app that asked a role enum directly, and it disagreed.
     *
     * The capabilities object answers from one definition; reading the enum raw here once
     * gave a machine credential `manage-billing` = true and `read-members` = false while a
     * human holding the same stored role got the exact opposite on both. One credential type
     * saying yes where the other says no, about the same organization, from the same role —
     * that is the shape of an authorization bug even while nobody happens to hold the role.
     *
     * `default => false` stays: an unknown capability string is refused rather than
     * admitted, so a route that names a capability nobody implemented fails closed.
     */
    private function permits(MembershipRole $role, string $capability): bool
    {
        $can = OrganizationCapabilities::of($role);

        return match ($capability) {
            'manage-environments' => $can->canManageEnvironments(),
            'manage-members' => $can->canManageMembers(),
            'manage-billing' => $can->canManageBilling(),
            'read-members' => $can->canReadMembers(),
            'read-billing' => $can->canReadBilling(),
            default => false,
        };
    }

    private function deny(string $error, string $message, int $status): Response
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
