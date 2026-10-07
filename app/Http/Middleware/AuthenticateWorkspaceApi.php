<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\Actions\WorkspaceScopes;
use App\Platform\OrganizationCapabilities;
use App\Platform\WorkspaceApiContext;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate a request on the workspace management plane with a `Bearer cbid_ws_…`
 * workspace key. Resolves the key to its organization and role, then optionally enforces a
 * SCOPE (a route-middleware parameter, `workspace.api:projects:write` — the same shape as
 * `env.api:apis:write` on the environment plane).
 *
 * A scope is two checks ({@see WorkspaceScopes}): the key's ROLE must hold the capability
 * the scope needs — answered THROUGH {@see OrganizationCapabilities}, which is the
 * console's derivation too — and the key must carry the scope, unless it was minted with
 * none and is bounded by its role alone. {@see WorkspaceKeyPrincipal} asks the same again
 * for the action, so a route and an action cannot disagree about a key.
 *
 * The capabilities object answers from one definition; reading the role enum raw here once
 * gave a machine credential `manage-billing` = true and `read-members` = false while a
 * human holding the same stored role got the exact opposite on both. One credential type
 * saying yes where the other says no, about the same organization, from the same role, is
 * the shape of an authorization bug even while nobody happens to hold the role.
 *
 * Never resolves an environment — this plane is global. An environment-scoped credential
 * (OAuth token, M2M, `cbid_env_…`) is not accepted here, and vice versa: credentials never
 * cross planes.
 */
final class AuthenticateWorkspaceApi
{
    public function __construct(
        private readonly OrganizationApiKeys $keys,
        private readonly WorkspaceApiContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $token = $request->bearerToken();
        $key = $token !== null ? $this->keys->resolve($token) : null;

        if ($key === null) {
            return $this->deny('unauthorized', 'A valid organization API key is required.', 401);
        }

        if ($scope !== null) {
            // An unknown scope is refused rather than admitted, so a route that names a
            // scope nobody defined fails closed.
            if (! WorkspaceScopes::roleAllows($key->role, $scope)) {
                return $this->deny('forbidden', "This key's role may not ".(WorkspaceScopes::capability($scope) ?? 'use '.$scope).'.', 403);
            }

            if (! $key->permits($scope)) {
                return $this->deny('forbidden', "This key is missing the required scope: {$scope}.", 403);
            }
        }

        $this->context->set($key);

        return $next($request);
    }

    private function deny(string $error, string $message, int $status): Response
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
