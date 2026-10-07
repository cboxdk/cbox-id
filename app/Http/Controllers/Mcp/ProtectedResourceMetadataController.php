<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use App\Http\Middleware\AuthenticateMcp;
use App\Platform\Actions\ActionRegistry;
use Cbox\Id\Api\Support\ServerMetadata;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /.well-known/oauth-protected-resource/mcp` — the RFC 9728 metadata of the MCP
 * server on this environment's host.
 *
 * An MCP client that is refused at `/mcp` reads the `resource_metadata` URL from the
 * challenge ({@see AuthenticateMcp}) and fetches this to learn which authorization server
 * issues credentials for the resource. RFC 9728 §3.1 puts a resource's document at the
 * well-known prefix followed by the resource's own path, which is why this is not the
 * framework's `/.well-known/oauth-protected-resource`: that one describes the environment
 * as a whole, and its `resource` is the issuer, not `/mcp`.
 *
 * - `resource` is the `/mcp` URL on the host the client asked. §3.3 requires it to equal
 *   the resource the client used, so it follows the request host rather than the
 *   canonical issuer — an environment reachable on an alias serves an MCP server there too.
 * - `authorization_servers` is the environment's issuer, the same value its discovery
 *   document asserts ({@see ServerMetadata::issuer()}).
 * - `scopes_supported` are the scopes the actions behind the tools require, from the
 *   registry, so a new action's scope appears here with no edit.
 *
 * Honest about today: `/mcp` accepts a management key, and an OAuth access token from that
 * issuer is the next step. The document already says where such a token would come from,
 * so a client that reads it is pointed at the right place the day it is accepted.
 */
final readonly class ProtectedResourceMetadataController
{
    public const string PATH = '/.well-known/oauth-protected-resource/mcp';

    public function __construct(private ActionRegistry $registry) {}

    public function __invoke(Request $request): JsonResponse
    {
        $scopes = array_values(array_unique(array_map(
            static fn ($action): string => $action->scope,
            array_values($this->registry->all()),
        )));
        sort($scopes);

        return response()->json([
            'resource' => $request->getSchemeAndHttpHost().'/mcp',
            'authorization_servers' => [ServerMetadata::issuer()],
            'scopes_supported' => $scopes,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Cbox ID MCP server',
        ]);
    }

    /** Where this document is served for the host $request arrived on. */
    public static function urlFor(Request $request): string
    {
        return $request->getSchemeAndHttpHost().self::PATH;
    }
}
