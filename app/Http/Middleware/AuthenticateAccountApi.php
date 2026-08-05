<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\AccountApiContext;
use App\Platform\AccountCapabilities;
use Cbox\Id\Platform\Contracts\AccountApiKeys;
use Cbox\Id\Platform\Enums\AccountRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate a request on the account management plane with a `Bearer cbid_acc_…`
 * account API key. Resolves the key to its account and role, then optionally
 * enforces a capability (passed as a route-middleware parameter, e.g.
 * `account.api:manage-members`) so a read-only key can't perform writes.
 *
 * Never resolves an environment — this plane is global. An environment-scoped
 * credential (OAuth token, M2M) is not accepted here, and vice versa: credentials
 * never cross planes.
 */
final class AuthenticateAccountApi
{
    public function __construct(
        private readonly AccountApiKeys $keys,
        private readonly AccountApiContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $capability = null): Response
    {
        $token = $request->bearerToken();
        $key = $token !== null ? $this->keys->resolve($token) : null;

        if ($key === null) {
            return $this->deny('unauthorized', 'A valid account API key is required.', 401);
        }

        if ($capability !== null && ! $this->permits($key->role, $capability)) {
            return $this->deny('forbidden', "This key's role may not {$capability}.", 403);
        }

        $this->context->set($key);

        return $next($request);
    }

    /**
     * THROUGH {@see AccountCapabilities}, which is the console's derivation too — this
     * was the last place in the app that asked `AccountRole` directly, and it disagreed.
     *
     * `AccountCapabilities` maps an account role onto the organization plane before
     * answering, and `Billing` maps to `Viewer`. Reading the enum raw therefore gave a
     * `role=billing` key `manage-billing` = true and `read-members` = false, while a human
     * Billing member in the console got the exact opposite on both. One credential type
     * saying yes where the other says no, about the same account, from the same stored
     * role — that is the shape of an authorization bug even while nobody is holding the
     * role, and `AccountRole::assignable()` no longer offers it precisely because the
     * mapping cannot be made faithful.
     *
     * `default => false` stays: an unknown capability string is refused rather than
     * admitted, so a route that names a capability nobody implemented fails closed.
     */
    private function permits(AccountRole $role, string $capability): bool
    {
        $can = AccountCapabilities::ofAccountRole($role);

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
