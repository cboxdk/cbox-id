<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Actions\Principal\RootPersonPrincipal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\Actions\WorkspaceScopes;
use App\Platform\OAuth\RootDelegatedAccess;
use App\Platform\OrganizationCapabilities;
use App\Platform\WorkspaceApiContext;
use Cbox\Id\OAuthServer\Dpop\DpopResourceGuard;
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
 * A MEMBER OF THE TEAM, THROUGH A TOKEN. Beside the key, the plane takes an access token
 * the PLATFORM ROOT issued one of the workspace's people for its `/mcp`
 * ({@see RootDelegatedAccess}) — the one token the `cbox` CLI and an agent signed in at the
 * root hold. The same two checks, of the person: their membership role holds the capability
 * the scope needs, and the token carries the scope ({@see RootPersonPrincipal}).
 *
 * Never resolves an environment — this plane is global. An environment-scoped credential
 * (an environment's own OAuth token, M2M, `cbid_env_…`) is not accepted here, and vice
 * versa: credentials never cross planes.
 */
final class AuthenticateWorkspaceApi
{
    public function __construct(
        private readonly OrganizationApiKeys $keys,
        private readonly WorkspaceApiContext $context,
        private readonly RootDelegatedAccess $root,
        private readonly DpopResourceGuard $dpop,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        // Either scheme: a DPoP-bound access token arrives as `DPoP <token>` (RFC 9449 §7.1).
        $token = $this->dpop->bearer($request);

        if (is_string($token) && $token !== '' && ! str_starts_with($token, AuthenticateMcp::KEY_PREFIX)) {
            return $this->asMember($request, $next, $scope);
        }

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

        try {
            return $next($request);
        } finally {
            // The credential authenticated THIS request and nothing after it.
            $this->context->clear();
        }
    }

    /**
     * The same two checks for a member of the team signing in through the platform root.
     *
     * @param  Closure(Request): Response  $next
     */
    private function asMember(Request $request, Closure $next, ?string $scope): Response
    {
        $person = $this->root->principal($request);

        if ($person === null) {
            return $this->deny('unauthorized', 'A valid workspace API key, or an access token the platform root issued you, is required.', 401);
        }

        $workspace = $person->workspace();

        if ($workspace === null) {
            return $this->deny('forbidden', 'You are not on a workspace\'s team.', 403);
        }

        if ($scope !== null) {
            if (! WorkspaceScopes::roleAllows($workspace->role, $scope)) {
                return $this->deny('forbidden', 'Your role in this workspace may not '.(WorkspaceScopes::capability($scope) ?? 'use '.$scope).'.', 403);
            }

            if (! $person->grants($scope)) {
                return $this->deny('forbidden', "This sign-in was not granted the required scope: {$scope}.", 403);
            }
        }

        $this->context->setPerson($person);

        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }

    private function deny(string $error, string $message, int $status): Response
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
