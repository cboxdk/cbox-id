<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Discovery;

use App\Platform\OAuth\RootAuthorizationServerMetadata;
use App\Platform\OAuth\SupportedPrompts;
use App\Platform\PlaneResolver;
use Cbox\Id\Api\Http\Controllers\AuthorizationServerMetadataController as FrameworkMetadataController;
use Cbox\Id\Api\Support\ServerMetadata;
use Illuminate\Http\JsonResponse;

/**
 * `GET /.well-known/oauth-authorization-server` (RFC 8414) — the same document as OIDC
 * discovery, so the same prompt values. Two documents that must agree are one answer.
 *
 * EXCEPT AT THE PLATFORM ROOT, which serves this document and not discovery: the root signs
 * MCP clients in for its own `/mcp` and is an identity provider for nobody, so it describes
 * that and nothing more ({@see RootAuthorizationServerMetadata}). The route reaches here on
 * the root only while `api.mcp.root_oauth` is on (`plane:mcp-discovery`).
 */
final class AuthorizationServerMetadataController extends FrameworkMetadataController
{
    public function __construct(
        private readonly SupportedPrompts $prompts,
        private readonly PlaneResolver $planes,
        private readonly RootAuthorizationServerMetadata $root,
    ) {}

    public function __invoke(): JsonResponse
    {
        if ($this->planes->onAccountPlane()) {
            return response()->json($this->root->document());
        }

        return response()->json($this->prompts->describe(ServerMetadata::document()));
    }
}
