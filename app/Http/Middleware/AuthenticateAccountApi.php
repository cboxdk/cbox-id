<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\AccountApiContext;
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

    private function permits(AccountRole $role, string $capability): bool
    {
        return match ($capability) {
            'manage-environments' => $role->canManageEnvironments(),
            'manage-members' => $role->canManageMembers(),
            'manage-billing' => $role->canManageBilling(),
            'read-members' => $role->canReadMembers(),
            'read-billing' => $role->canReadBilling(),
            default => false,
        };
    }

    private function deny(string $error, string $message, int $status): Response
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
