<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\ActionController;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionRoutes;
use App\Platform\Actions\Principal\EnvironmentMemberPrincipal;
use App\Platform\Actions\Principal\RootPersonPrincipal;
use App\Platform\EnvironmentApiContext;
use App\Platform\OAuth\DelegatedAccess;
use App\Platform\OAuth\RootDelegatedAccess;
use Cbox\Id\OAuthServer\Dpop\DpopResourceGuard;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
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
 *
 * FROM THE PLATFORM ROOT, ONE ENVIRONMENT PER REQUEST, NAMED IN A HEADER. On the root host
 * of a multi-tenant deployment the token is the root's ({@see RootDelegatedAccess}) and its
 * person is one of a workspace's team, who may act in every environment of the workspace
 * they could open the console of. The request names which one in `Cbox-Environment` (an
 * id or a slug) — the REST twin of the MCP tools' `environment` argument — and the person
 * is bound to it ({@see RootPersonPrincipal::inEnvironment()}: a 404 for one they cannot
 * reach, a 403 for a role that administers none) and the rest of the request runs INSIDE
 * that environment's tenancy ({@see EnvironmentMemberPrincipal::within()}).
 *
 * A header rather than a path (`/api/v1/environments/{environment}/…`) because it reuses
 * every route {@see ActionRoutes} already registers, unchanged: the same operations, the
 * same OpenAPI document, the same paths a key uses on the environment's own host. A second
 * tree of every route would be a second surface to document, rate-limit and keep in step.
 */
final class AuthenticateEnvironmentApi
{
    /** The route-middleware parameter that admits a person's access token beside a key. */
    public const string DELEGATED = 'delegated';

    /** The header naming the environment a root-host token acts in: an id or a slug. */
    public const string ENVIRONMENT_HEADER = 'Cbox-Environment';

    public function __construct(
        private readonly EnvironmentApiKeys $keys,
        private readonly EnvironmentApiContext $context,
        private readonly ManagementScopes $scopes,
        private readonly DelegatedAccess $delegated,
        private readonly DpopResourceGuard $dpop,
        private readonly RootDelegatedAccess $root,
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
        if ($this->root->onRootHost()) {
            return $this->asWorkspaceMember($request, $next, $scope, $admitted);
        }

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

    /**
     * The same steps for a root-host token, plus the one only it needs: which environment.
     * The approval poll names no scope and no environment — the person polls the approvals
     * they raised in any of them — and is the only route admitted without one.
     *
     * @param  Closure(Request): Response  $next
     */
    private function asWorkspaceMember(Request $request, Closure $next, ?string $scope, bool $admitted): Response
    {
        $person = $this->root->principal($request);

        if ($person === null) {
            return $this->deny('unauthorized', 'A valid environment API key, or an access token the platform root issued for its management plane, is required.', 401);
        }

        if (! $admitted) {
            return $this->deny('forbidden', 'This endpoint takes an environment API key (Bearer cbid_env_…). A signed-in access token reaches the management actions and their approvals only.', 403);
        }

        if ($scope === null) {
            $this->context->setDelegated($person);

            try {
                return $next($request);
            } finally {
                $this->context->clear();
            }
        }

        $reference = $request->headers->get(self::ENVIRONMENT_HEADER);

        if (! is_string($reference) || trim($reference) === '') {
            return $this->deny('environment_required', 'Name the environment to act in with the '.self::ENVIRONMENT_HEADER.' header: an environment id or slug of your workspace.', 400);
        }

        try {
            $member = $person->inEnvironment($reference);
        } catch (ActionRefused $refused) {
            return $this->deny($refused->error, $refused->getMessage(), $refused->status);
        } catch (AuthorizationException $forbidden) {
            return $this->deny('forbidden', $forbidden->getMessage(), 403);
        }

        if (! $this->scopes->knows($scope) || ! in_array($scope, $member->managementScopes(), true)) {
            return $this->deny('forbidden', "This sign-in was not granted the required scope: {$scope}.", 403);
        }

        $this->context->setDelegated($member);

        try {
            return $member->within(static fn (): Response => $next($request));
        } finally {
            $this->context->clear();
        }
    }

    private function deny(string $error, string $message, int $status): Response
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
