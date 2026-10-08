<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Mcp\McpCaller;
use App\Mcp\McpProtectedResources;
use App\Platform\Actions\Principal\DelegatedTokenPrincipal;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\RootPersonPrincipal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\EnvironmentApiContext;
use App\Platform\OAuth\DelegatedAccess;
use App\Platform\OAuth\RootDelegatedAccess;
use App\Platform\WorkspaceApiContext;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\Dpop\DpopResourceGuard;
use Cbox\Id\OAuthServer\Support\BearerChallenge;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Contracts\OrganizationApiKeys;
use Cbox\Id\Platform\DatabaseOrganizationApiKeys;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate a request to the MCP server at `/mcp` and name its {@see Principal}.
 *
 * Three credentials, each resolved exactly as its REST door resolves it:
 *
 * - an environment management key (`Bearer cbid_env_…`), as {@see AuthenticateEnvironmentApi}
 *   does: within the environment the host already resolved, so a key minted for another
 *   environment simply does not resolve here. It sets {@see EnvironmentApiContext}, because
 *   the audit decorator reads the key from there to name it as the actor of whatever the
 *   framework records underneath an action — an MCP call leaves the same trail as REST.
 * - a workspace key (`Bearer cbid_ws_…`), as {@see AuthenticateWorkspaceApi} does: above
 *   every environment, so on any host. It sets {@see WorkspaceApiContext}.
 * - anything else is an OAuth ACCESS TOKEN a person signed an MCP client in for, with this
 *   environment's issuer as the authorization server: read by {@see DelegatedAccess} — live,
 *   audienced to this `/mcp`, a person's, DPoP-proven when bound — into a
 *   {@see DelegatedTokenPrincipal}, and set on {@see EnvironmentApiContext} so the trail
 *   names the person and the client.
 * - ON THE ROOT HOST of a multi-tenant deployment that token is the PLATFORM ROOT's, and
 *   its person is a workspace member or an operator: read by {@see RootDelegatedAccess}
 *   into a {@see RootPersonPrincipal}, who sees the workspace's tools as a member, each
 *   environment's tools with an `environment` argument naming where to act, their own
 *   account's, and — an operator — the deployment's.
 *
 * Each sees the tools of its own plane only — the principal refuses the other plane's
 * actions, so they are never listed to it — and a person sees what their token's scopes
 * AND their own rights here both allow.
 *
 * Unlike the REST middleware it takes no scope parameter: one endpoint carries every tool,
 * so the scope is a per-tool question. Tools the principal may not run are not listed, and
 * the action runner refuses them again if called anyway. That holds for a token too: one
 * missing a scope is answered with fewer tools, never a 403 — `insufficient_scope` is for a
 * resource that has one scope to ask for.
 *
 * A request without a usable credential gets the RFC 6750 challenge with the RFC 9728
 * `resource_metadata` pointer, which is how an MCP client discovers where to sign in:
 * the document the framework serves for {@see McpProtectedResources}. `error="invalid_token"` only when a token
 * was presented — §3.1 says a request that carried none gets no error code.
 */
final class AuthenticateMcp
{
    /** What every management key this platform mints starts with, whatever its plane. */
    public const string KEY_PREFIX = 'cbid_';

    public function __construct(
        private readonly EnvironmentApiKeys $keys,
        private readonly OrganizationApiKeys $workspaceKeys,
        private readonly EnvironmentApiContext $context,
        private readonly WorkspaceApiContext $workspace,
        private readonly McpCaller $caller,
        private readonly ProtectedResources $resources,
        private readonly DpopResourceGuard $dpop,
        private readonly DelegatedAccess $delegated,
        private readonly RootDelegatedAccess $root,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Either scheme: a DPoP-bound access token arrives as `DPoP <token>` (RFC 9449 §7.1).
        $token = $this->dpop->bearer($request);
        $principal = is_string($token) && $token !== '' ? $this->principalFor($token, $request) : null;

        if ($principal === null) {
            return $this->challenge(presented: is_string($token) && $token !== '');
        }

        $this->caller->set($principal);

        try {
            return $next($request);
        } finally {
            // This request's credential, and nothing after it (see McpCaller).
            $this->caller->clear();
            $this->context->clear();
            $this->workspace->clear();
        }
    }

    /**
     * The principal a bearer credential stands for, or null when it stands for none.
     *
     * A management key is told apart by its `cbid_` prefix — every key this platform mints
     * carries one, and no access token does (they are JWTs) — so a key is never introspected
     * as a token, nor a token looked up as a key.
     */
    private function principalFor(string $token, Request $request): ?Principal
    {
        if (str_starts_with($token, DatabaseOrganizationApiKeys::prefix())) {
            $workspaceKey = $this->workspaceKeys->resolve($token);

            if ($workspaceKey === null) {
                return null;
            }

            $this->workspace->set($workspaceKey);

            return new WorkspaceKeyPrincipal($workspaceKey);
        }

        if (! str_starts_with($token, self::KEY_PREFIX)) {
            $person = $this->root->onRootHost()
                ? $this->root->principal($request)
                : $this->delegated->principal($request);

            if ($person !== null) {
                $this->context->setDelegated($person);
            }

            return $person;
        }

        $key = $this->keys->resolve($token);

        if ($key === null) {
            return null;
        }

        $this->context->set($key);

        return new EnvironmentKeyPrincipal($key);
    }

    private function challenge(bool $presented): Response
    {
        $resource = $this->resources->forMetadataPath('/.well-known/oauth-protected-resource'.McpProtectedResources::PATH);
        $challenge = $resource === null ? new BearerChallenge : BearerChallenge::for($resource);

        if ($presented) {
            $challenge = $challenge->withError('invalid_token');
        }

        return response()->json(
            ['error' => 'unauthorized', 'message' => 'A valid management key (Bearer cbid_env_… for an environment, cbid_ws_… for a workspace), or an access token this environment issued for its MCP server, is required.'],
            401,
            $challenge->headers(),
        );
    }
}
