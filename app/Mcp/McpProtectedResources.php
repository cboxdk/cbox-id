<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\ValueObjects\ProtectedResource;

/**
 * The resources this deployment serves itself: whatever config declares, plus the MCP
 * server at `{issuer}/mcp` in every environment.
 *
 * Declared here rather than in config because its scopes are not a list anybody keeps:
 * they are the scopes the environment plane's actions require, read from the registry, so
 * an action added to `app/Actions` is a scope `/mcp` advertises with no second edit. The
 * framework serves the RFC 9728 document for it (`/.well-known/oauth-protected-resource/mcp`)
 * and audiences an RFC 8707 `resource=…/mcp` token to it.
 */
final readonly class McpProtectedResources implements ProtectedResources
{
    public const string PATH = '/mcp';

    public function __construct(
        private ProtectedResources $configured,
        private IssuerResolver $issuers,
        private ActionRegistry $registry,
    ) {}

    public function all(): array
    {
        return [...$this->configured->all(), $this->mcp()];
    }

    public function find(string $identifier): ?ProtectedResource
    {
        foreach ($this->all() as $resource) {
            if ($resource->identifier === $identifier) {
                return $resource;
            }
        }

        return null;
    }

    public function forMetadataPath(string $path): ?ProtectedResource
    {
        $path = '/'.trim($path, '/');

        foreach ($this->all() as $resource) {
            if ($resource->metadataPath() === $path) {
                return $resource;
            }
        }

        return null;
    }

    public function issuerScopes(): array
    {
        return $this->configured->issuerScopes();
    }

    /** The MCP server of the environment being served. */
    public function mcp(): ProtectedResource
    {
        $scopes = array_values(array_unique(array_map(
            static fn ($action): string => $action->scope,
            $this->registry->forPlane(ActionPlane::Environment),
        )));
        sort($scopes);

        return new ProtectedResource(
            identifier: rtrim($this->issuers->issuer(), '/').self::PATH,
            scopes: $scopes,
            name: 'Cbox ID MCP server',
        );
    }
}
