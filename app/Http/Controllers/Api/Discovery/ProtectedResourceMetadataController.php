<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Discovery;

use App\Http\Middleware\AuthenticateMcp;
use App\Mcp\McpProtectedResources;
use App\Platform\OAuth\RootMcpAudiences;
use App\Platform\PlaneResolver;
use Cbox\Id\Api\Http\Controllers\ProtectedResourceMetadataController as FrameworkMetadataController;
use Cbox\Id\OAuthServer\Contracts\ProtectedResources;
use Illuminate\Http\JsonResponse;

/**
 * `GET /.well-known/oauth-protected-resource/{path}` (RFC 9728 §3.1) — the framework's
 * document for a declared resource, and at the PLATFORM ROOT for its `/mcp` alone.
 *
 * The root's `/mcp` answers `401` with a pointer here ({@see AuthenticateMcp}),
 * which is how an MCP client finds out where to sign in. Any other resource the root
 * environment's config might declare is not one an MCP client signs in for at the root —
 * the root issues tokens for its `/mcp` only ({@see RootMcpAudiences}) —
 * so describing it there would advertise an audience nothing can be issued for.
 */
final class ProtectedResourceMetadataController extends FrameworkMetadataController
{
    public function __construct(
        ProtectedResources $resources,
        private readonly PlaneResolver $planes,
    ) {
        parent::__construct($resources);
    }

    public function show(string $path): JsonResponse
    {
        if ($this->planes->onAccountPlane() && '/'.trim($path, '/') !== McpProtectedResources::PATH) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return parent::show($path);
    }
}
