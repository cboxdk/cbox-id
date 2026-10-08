<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use Cbox\Id\OAuthServer\Contracts\AudienceResolver;
use Cbox\Id\OAuthServer\Enums\ProtocolScope;
use Cbox\Id\OAuthServer\Exceptions\InvalidAudience;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ResolvedAudience;
use Cbox\Id\OAuthServer\ValueObjects\ScopeHolder;

/**
 * Where a token for a SELF-REGISTERED client may point when the platform root issues it:
 * the root's own `/mcp`, and nowhere else.
 *
 * A decorator over the framework's resolver rather than a rule of its own, because the
 * framework asks this ONE question for every token it mints — at `/authorize` (the consent
 * controller asks it before the person sees anything), and at the token endpoint for the
 * code, the refresh and every other grant. A wall placed anywhere else would be one grant
 * away from a hole.
 *
 * The framework already refuses a self-registered client an UNKNOWN resource and a declared
 * one that does not take such clients. On a tenant's host that is the whole rule: the host
 * is an identity provider, and its issuer and its registered APIs are fair audiences. On
 * the root neither is — so here, for such a client ({@see RootMcpOAuth::governs()}):
 *
 *  - no `resource` means the root's `/mcp`, not "the issuer" (the framework's default for a
 *    token that names nothing): a token audienced to the root's issuer is a credential for
 *    every resource server that trusts it;
 *  - any other `resource` is `invalid_target` — the issuer, a registered API of the root
 *    environment (an organization admin at the root can create those), anything declared
 *    in config;
 *  - a protocol scope other than `offline_access` is `invalid_scope`: no `openid`, so no ID
 *    token and no UserInfo, which the root does not serve.
 *
 * Every other client, and every request anywhere but the root, is the framework's answer
 * unchanged — the root's own `cbox` CLI included.
 */
final readonly class RootMcpAudiences implements AudienceResolver
{
    public function __construct(private AudienceResolver $inner) {}

    /**
     * @throws InvalidAudience
     */
    public function resolve(Client $client, array $scopes, ?string $resource): ResolvedAudience
    {
        // Resolved per call: the framework holds its resolver as a singleton, and what this
        // asks — which environment the request is in — is per request.
        $root = app(RootMcpOAuth::class);

        if (! $root->governs($client)) {
            return $this->inner->resolve($client, $scopes, $resource);
        }

        $mcp = $root->resource();

        if ($mcp === null || ! $mcp->dynamicClients) {
            throw InvalidAudience::notOfferedToDynamicClients($resource ?? 'the platform root');
        }

        if ($resource !== null && rtrim($resource, '/') !== rtrim($mcp->identifier, '/')) {
            throw new InvalidAudience('invalid_target', "The platform root issues tokens for its MCP server ({$mcp->identifier}) only, not for {$resource}.");
        }

        $refused = array_values(array_filter(
            $scopes,
            static fn (string $scope): bool => ProtocolScope::isProtocol($scope) && $scope !== ProtocolScope::OfflineAccess->value,
        ));

        if ($refused !== []) {
            throw new InvalidAudience('invalid_scope', 'The platform root signs MCP clients in for its MCP server only; it issues no ID tokens, so it does not grant: '.implode(' ', $refused).'.');
        }

        return $this->inner->resolve($client, $scopes, $mcp->identifier);
    }

    public function ungrantable(ScopeHolder $holder, array $scopes): array
    {
        return $this->inner->ungrantable($holder, $scopes);
    }
}
