<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Platform\Actions\AccountScopes;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\PlatformScopes;
use App\Platform\Actions\WorkspaceScopes;
use App\Platform\OAuth\DelegatedAccess;
use App\Platform\OAuth\RootDelegatedAccess;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Cbox\Id\OAuthServer\ValueObjects\ProtectedResource;
use Cbox\Id\Platform\PlatformRoot;

/**
 * The resources this deployment serves itself: whatever config declares, plus the MCP
 * server at `{issuer}/mcp` in every environment.
 *
 * Declared here rather than in config because its scopes are not a list anybody keeps:
 * they are the scopes the environment plane's actions require, read from the registry, so
 * an action added to `app/Actions` is a scope `/mcp` advertises with no second edit. The
 * framework serves the RFC 9728 document for it (`/.well-known/oauth-protected-resource/mcp`)
 * and audiences an RFC 8707 `resource=…/mcp` token to it.
 *
 * It is the audience of the whole environment management plane, not only of the MCP
 * transport: the REST environment API accepts the same token (see
 * {@see DelegatedAccess} for why one audience rather than two).
 *
 * WHICH SCOPES, BY WHOSE `/mcp` IT IS. A person may use more than one plane through the
 * one token, so the resource accepts every scope they could be handed there:
 *
 *  - on an environment's host: that environment's plane, and the person's own account
 *    (`account:*`, `/api/v1/me` on the same host);
 *  - at the PLATFORM ROOT: those, and the workspace plane's ({@see WorkspaceScopes}) and the
 *    operator API's ({@see PlatformScopes}) — because the root's `/mcp` is where the people
 *    who administer a hosted deployment sign in: a workspace's team, for the workspace and
 *    (named per call) each of its environments, and the operators running it
 *    ({@see RootDelegatedAccess}). A scope listed here buys nothing by itself: every action
 *    still asks whether the person holds the right.
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
        $planes = $this->servingPlatformRoot()
            ? [ActionPlane::Environment, ActionPlane::Account, ActionPlane::Workspace, ActionPlane::Platform]
            : [ActionPlane::Environment, ActionPlane::Account];

        $scopes = [];

        foreach ($planes as $plane) {
            foreach ($this->registry->forPlane($plane) as $action) {
                $scopes[] = $action->scope;
            }
        }

        // Every scope the plane catalogues define, not only the ones an action guards today:
        // a person's token is held to its scopes for as long as it lives, and a catalogue
        // scope no action uses yet is one a client can ask for already.
        $catalogued = $this->servingPlatformRoot()
            ? [...AccountScopes::all(), ...WorkspaceScopes::all(), ...PlatformScopes::all()]
            : AccountScopes::all();

        $scopes = array_values(array_unique([...$scopes, ...$catalogued]));
        sort($scopes);

        return new ProtectedResource(
            identifier: rtrim($this->issuers->issuer(), '/').self::PATH,
            scopes: $scopes,
            // An MCP client registers itself — that is the whole MCP authorization model —
            // so self-registered clients may be audienced here (`api.mcp.dynamic_clients`).
            // What such a token may DO is still the person's to decide twice: on the
            // consent screen, which can never be skipped for a client like this, and by
            // their own rights, which the action layer asks on every call.
            dynamicClients: config('api.mcp.dynamic_clients', true) === true,
            name: 'Cbox ID MCP server',
        );
    }

    /**
     * Whether the environment being served is the platform root. Asked of the live context —
     * the resource is declared per request, inside whichever environment is current — and
     * never true where no root exists.
     */
    private function servingPlatformRoot(): bool
    {
        $current = app(EnvironmentContext::class)->current()?->environmentKey();
        $root = app(PlatformRoot::class)->environment()?->environmentKey();

        return $current !== null && $root !== null && $current === $root;
    }
}
