<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Mcp\McpCaller;
use App\Mcp\McpProtectedResources;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\Actions\Principal\WorkspaceKeyPrincipal;
use App\Platform\EnvironmentApiContext;
use App\Platform\WorkspaceApiContext;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
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
 * Two credentials, the two the REST API takes, each resolved exactly as its REST
 * middleware resolves it:
 *
 * - an environment management key (`Bearer cbid_env_…`), as {@see AuthenticateEnvironmentApi}
 *   does: within the environment the host already resolved, so a key minted for another
 *   environment simply does not resolve here. It sets {@see EnvironmentApiContext}, because
 *   the audit decorator reads the key from there to name it as the actor of whatever the
 *   framework records underneath an action — an MCP call leaves the same trail as REST.
 * - a workspace key (`Bearer cbid_ws_…`), as {@see AuthenticateWorkspaceApi} does: above
 *   every environment, so on any host. It sets {@see WorkspaceApiContext}.
 *
 * Each sees the tools of its own plane only — the principal refuses the other plane's
 * actions, so they are never listed to it.
 *
 * Unlike the REST middleware it takes no scope parameter: one endpoint carries every tool,
 * so the scope is a per-tool question. Tools the principal may not run are not listed, and
 * the action runner refuses them again if called anyway.
 *
 * A request without a usable credential gets the RFC 6750 challenge with the RFC 9728
 * `resource_metadata` pointer, which is how an MCP client discovers where to sign in:
 * the document the framework serves for {@see McpProtectedResources}. `error="invalid_token"` only when a token
 * was presented — §3.1 says a request that carried none gets no error code.
 */
final class AuthenticateMcp
{
    public function __construct(
        private readonly EnvironmentApiKeys $keys,
        private readonly OrganizationApiKeys $workspaceKeys,
        private readonly EnvironmentApiContext $context,
        private readonly WorkspaceApiContext $workspace,
        private readonly McpCaller $caller,
        private readonly ProtectedResources $resources,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $principal = is_string($token) && $token !== '' ? $this->principalFor($token) : null;

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
     * THE SEAM FOR DELEGATED ACCESS. An MCP client that signs a person in (OAuth, with
     * the environment's issuer as authorization server) presents an access token instead
     * of a key. That becomes a second branch here — introspect, check the audience is this
     * `/mcp` resource, and return a principal acting for that person — and nothing past
     * this method changes. Until then only a management key is accepted.
     */
    private function principalFor(string $token): ?Principal
    {
        if (str_starts_with($token, DatabaseOrganizationApiKeys::prefix())) {
            $workspaceKey = $this->workspaceKeys->resolve($token);

            if ($workspaceKey === null) {
                return null;
            }

            $this->workspace->set($workspaceKey);

            return new WorkspaceKeyPrincipal($workspaceKey);
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
            ['error' => 'unauthorized', 'message' => 'A valid management key (Bearer cbid_env_… for an environment, cbid_ws_… for a workspace) is required.'],
            401,
            $challenge->headers(),
        );
    }
}
