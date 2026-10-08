<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Platform\PlaneResolver;
use Cbox\Id\OAuthServer\Enums\ProtocolScope;
use Cbox\Id\OAuthServer\Support\SelfRegisteredScopes;

/**
 * The scopes a client that registered ITSELF may hold — narrowed, at the platform root, to
 * the root's `/mcp` and `offline_access`.
 *
 * The framework's rule ({@see SelfRegisteredScopes}) is every resource open to such clients
 * plus the protocol scopes the operator allows them, which on a tenant's host means
 * `openid`, `profile` and `email` too: the host is an identity provider, and an MCP client
 * there may sign the person in for an ID token as well. The root is not, so a client
 * registering there (RFC 7591, `mcp` profile) or described by a metadata document is held
 * to the one resource it can be issued a token for, and the one protocol scope that means
 * something there — a refresh token. Asking for anything else at registration narrows it
 * away, and at `/authorize` is `invalid_scope` ({@see RootMcpAudiences} is the second wall).
 *
 * Asked per call: the framework's registrar and document resolver are singletons, and the
 * same process serves the root and every tenant.
 */
final class RootMcpSelfRegisteredScopes extends SelfRegisteredScopes
{
    public function allowed(): array
    {
        $allowed = parent::allowed();

        if (! app(PlaneResolver::class)->onAccountPlane()) {
            return $allowed;
        }

        $mcp = app(RootDelegatedAccess::class)->resource();
        $resourceScopes = $mcp !== null && $mcp->dynamicClients ? $mcp->scopes : [];

        return array_values(array_filter(
            $allowed,
            static fn (string $scope): bool => $scope === ProtocolScope::OfflineAccess->value
                || (! ProtocolScope::isProtocol($scope) && in_array($scope, $resourceScopes, true)),
        ));
    }
}
