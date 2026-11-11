<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Platform\Scopes\ScopeRegistry;
use Cbox\Id\OAuthServer\ValueObjects\ProtectedResource;

/**
 * An MCP resource that PUBLISHES one name per scope and ACCEPTS either spelling until v3.1.
 *
 * `scopes` is what the resource metadata advertises (`scopes_supported`) and what a client
 * that registers itself without naming a scope is given — so it holds each scope once, under
 * the name its action declares: listing both put both on the consent screen and in the
 * token. A client that asks for the other spelling — one registered before v3 asking for
 * `team:read`, or one already asking for `id:members:read` on a plane still declaring `team:read` —
 * is still granted the name it asked for ({@see ScopeRegistry::satisfies()}), and every check
 * accepts both.
 */
final readonly class TransitionalProtectedResource extends ProtectedResource
{
    public function accepts(string $scope): bool
    {
        return parent::accepts($scope) || ScopeRegistry::satisfies($this->scopes, $scope);
    }
}
