<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\ActionController;
use App\Platform\Actions\ActionRoutes;
use App\Platform\EnvironmentApiContext;
use App\Platform\OAuth\DelegatedAccess;
use Cbox\Id\OAuthServer\Dpop\DpopResourceGuard;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate a request on the ENVIRONMENT management plane with a
 * `Bearer cbid_env_…` key. The environment is already resolved from the request
 * host by the framework's ResolveEnvironment middleware, so this resolves the key
 * WITHIN that environment: because the key model is hard environment-scoped, a key
 * belonging to another environment simply doesn't resolve here — a credential can
 * never act outside the host it was minted for.
 *
 * A required scope (route-middleware parameter, e.g. `env.api:organizations:write`)
 * is enforced deny-by-default, so a read-only key can't mutate.
 *
 * A PERSON'S ACCESS TOKEN, ON THE ROUTES THAT SAY SO. A route that also names
 * {@see self::DELEGATED} (`env.api:apis:write,delegated` — every action route
 * {@see ActionRoutes} registers, and the approval poll) accepts the token `/mcp` accepts,
 * read the same way ({@see DelegatedAccess}): one audience, the environment's `/mcp`, so the
 * one token the `cbox` CLI holds works at both doors. Its scope is enforced here like a
 * key's; what the PERSON may do is the action's question ({@see ActionController}).
 *
 * The routes that are not actions yet read the KEY, and act as it — there is no person
 * for them to act as. They answer a valid token with a 403 that says which credential they
 * take, rather than letting a missing key surface as a 500 three calls down.
 */
final class AuthenticateEnvironmentApi
{
    /** The route-middleware parameter that admits a person's access token beside a key. */
    public const string DELEGATED = 'delegated';

    public function __construct(
        private readonly EnvironmentApiKeys $keys,
        private readonly EnvironmentApiContext $context,
        private readonly ManagementScopes $scopes,
        private readonly DelegatedAccess $delegated,
        private readonly DpopResourceGuard $dpop,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $scope = null, ?string $doors = null): Response
    {
        $scope = $scope === '' ? null : $scope;
        // Either scheme: a DPoP-bound access token arrives as `DPoP <token>` (RFC 9449 §7.1).
        $token = $this->dpop->bearer($request);

        if (is_string($token) && $token !== '' && ! str_starts_with($token, AuthenticateMcp::KEY_PREFIX)) {
            return $this->asPerson($request, $next, $scope, $doors === self::DELEGATED);
        }

        $key = $token !== null ? $this->keys->resolve($token) : null;

        if ($key === null) {
            return $this->deny('unauthorized', 'A valid environment API key is required.', 401);
        }

        // Deny-by-default twice over: a scope the vocabulary does not know is refused even
        // if a key somehow carries it, and a known one only if this key carries it.
        if ($scope !== null && (! $this->scopes->knows($scope) || ! $key->can($scope))) {
            return $this->deny('forbidden', "This key is missing the required scope: {$scope}.", 403);
        }

        $this->context->set($key);

        try {
            return $next($request);
        } finally {
            // The key authenticated THIS request and nothing after it (see clear()).
            $this->context->clear();
        }
    }

    /**
     * The same three steps for a person's token: valid at all, admitted on this route, and
     * carrying the route's scope.
     *
     * @param  Closure(Request): Response  $next
     */
    private function asPerson(Request $request, Closure $next, ?string $scope, bool $admitted): Response
    {
        $person = $this->delegated->principal($request);

        if ($person === null) {
            return $this->deny('unauthorized', 'A valid environment API key, or an access token this environment issued for its management plane, is required.', 401);
        }

        if (! $admitted) {
            return $this->deny('forbidden', 'This endpoint takes an environment API key (Bearer cbid_env_…). A signed-in access token reaches the management actions and their approvals only.', 403);
        }

        if ($scope !== null && (! $this->scopes->knows($scope) || ! in_array($scope, $person->managementScopes(), true))) {
            return $this->deny('forbidden', "This sign-in was not granted the required scope: {$scope}.", 403);
        }

        $this->context->setDelegated($person);

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
