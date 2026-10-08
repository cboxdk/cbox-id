<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Actions\AccountScopes;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\PlatformScopes;
use App\Platform\Actions\Principal\DelegatedTokens;
use App\Platform\Actions\Principal\OperatorPrincipal;
use App\Platform\Actions\Principal\PersonPrincipal;
use App\Platform\DelegatedApiContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The REST door to the planes only a PERSON reaches: `platform.api:<scope>` in front of
 * `/api/v1/platform/…` and `account.api:<scope>` in front of `/api/v1/me/…`.
 *
 * NO KEY OF ANY KIND GETS THROUGH. An environment key and a workspace key speak for a
 * customer; these planes act as one person — the deployment's operator, or the account's
 * own holder — so the only credential accepted is a token that person delegated, resolved
 * by {@see DelegatedTokens}. Anything that resolver does not recognise is a 401, a
 * management key included, and nothing about the bearer is looked up anywhere else: a
 * door that tried each key store in turn to explain its refusal would be a door that
 * touched every key store.
 *
 * On the PLATFORM plane the person must also be an operator — an
 * {@see OperatorPrincipal} — or it is a 403: the token is real, its person is not staff.
 * Then the scope: one the plane defines ({@see PlatformScopes}, {@see AccountScopes} — an
 * unknown scope fails closed) and one the person delegated. The principal is asked again
 * when the action runs, for the reason the key middlewares give: an action reached by any
 * other door must not depend on this one.
 *
 * WHICH TOKEN. {@see DelegatedTokens} reads it by the issuer that signed it: the platform
 * root's (a workspace member or an operator, at the root and on the platform plane, which
 * resolves no environment), or an environment's own, for its own subject's account on its
 * host. Either may arrive under the `DPoP` scheme, and a bound one needs its proof.
 */
final readonly class AuthenticateDelegatedApi
{
    public function __construct(
        private DelegatedTokens $tokens,
        private DelegatedApiContext $context,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $plane, ?string $scope = null): Response
    {
        $plane = ActionPlane::from($plane);
        $principal = $this->tokens->principal($request);

        if (! $principal instanceof PersonPrincipal) {
            return $this->deny('unauthorized', $plane === ActionPlane::Platform
                ? 'This API accepts only an access token delegated by a platform operator. Management keys are not accepted here.'
                : 'This API accepts only an access token you delegated. Management keys are not accepted here.', 401);
        }

        if ($plane === ActionPlane::Platform && ! $principal instanceof OperatorPrincipal) {
            return $this->deny('forbidden', 'Only a platform operator can use this API.', 403);
        }

        if ($scope !== null && (! self::knows($plane, $scope) || ! $principal->grants($scope))) {
            return $this->deny('forbidden', "This token is missing the required scope: {$scope}.", 403);
        }

        $this->context->set($principal);

        try {
            return $next($request);
        } finally {
            // The token authenticated THIS request and nothing after it.
            $this->context->clear();
        }
    }

    private static function knows(ActionPlane $plane, string $scope): bool
    {
        return match ($plane) {
            ActionPlane::Platform => PlatformScopes::knows($scope),
            ActionPlane::Account => AccountScopes::knows($scope),
            default => false,
        };
    }

    private function deny(string $error, string $message, int $status): Response
    {
        return response()->json(['error' => $error, 'message' => $message], $status);
    }
}
