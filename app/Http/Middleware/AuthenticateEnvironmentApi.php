<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\EnvironmentApiContext;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;
use Cbox\Id\Platform\Enums\EnvironmentApiScope;
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
 */
final class AuthenticateEnvironmentApi
{
    public function __construct(
        private readonly EnvironmentApiKeys $keys,
        private readonly EnvironmentApiContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $token = $request->bearerToken();
        $key = $token !== null ? $this->keys->resolve($token) : null;

        if ($key === null) {
            return $this->deny('unauthorized', 'A valid environment API key is required.', 401);
        }

        $required = $scope !== null ? EnvironmentApiScope::tryFrom($scope) : null;

        if ($scope !== null && ($required === null || ! $key->can($required))) {
            return $this->deny('forbidden', "This key is missing the required scope: {$scope}.", 403);
        }

        $this->context->set($key);

        return $next($request);
    }

    private function deny(string $error, string $message, int $status): Response
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
