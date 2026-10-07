<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Mcp\ProtectedResourceMetadataController;
use App\Mcp\McpCaller;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\Principal;
use App\Platform\EnvironmentApiContext;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate a request to the MCP server at `/mcp` and name its {@see Principal}.
 *
 * Today the one credential is an environment management key (`Bearer cbid_env_…`),
 * resolved exactly as {@see AuthenticateEnvironmentApi} resolves it: within the
 * environment the host already resolved, so a key minted for another environment simply
 * does not resolve here. It also sets {@see EnvironmentApiContext}, because the audit
 * decorator reads the key from there to name it as the actor of whatever the framework
 * records underneath an action — an MCP call must leave the same trail as a REST call.
 *
 * Unlike the REST middleware it takes no scope parameter: one endpoint carries every tool,
 * so the scope is a per-tool question. Tools the principal may not run are not listed, and
 * the action runner refuses them again if called anyway.
 *
 * A request without a usable credential gets the RFC 6750 challenge with the RFC 9728
 * `resource_metadata` pointer, which is how an MCP client discovers where to sign in:
 * {@see ProtectedResourceMetadataController}. `error="invalid_token"` only when a token
 * was presented — §3.1 says a request that carried none gets no error code.
 */
final class AuthenticateMcp
{
    public function __construct(
        private readonly EnvironmentApiKeys $keys,
        private readonly EnvironmentApiContext $context,
        private readonly McpCaller $caller,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $principal = is_string($token) && $token !== '' ? $this->principalFor($token) : null;

        if ($principal === null) {
            return $this->challenge($request, presented: is_string($token) && $token !== '');
        }

        $this->caller->set($principal);

        try {
            return $next($request);
        } finally {
            // This request's credential, and nothing after it (see McpCaller).
            $this->caller->clear();
            $this->context->clear();
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
        $key = $this->keys->resolve($token);

        if ($key === null) {
            return null;
        }

        $this->context->set($key);

        return new EnvironmentKeyPrincipal($key);
    }

    private function challenge(Request $request, bool $presented): Response
    {
        $challenge = 'Bearer resource_metadata="'.ProtectedResourceMetadataController::urlFor($request).'"';

        if ($presented) {
            $challenge .= ', error="invalid_token"';
        }

        return response()->json(
            ['error' => 'unauthorized', 'message' => 'A valid environment management key (Bearer cbid_env_…) is required.'],
            401,
            ['WWW-Authenticate' => $challenge],
        );
    }
}
