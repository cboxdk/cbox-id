<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Platform\Api\ApiUrls;
use App\Platform\Api\ManagementAudience;
use App\Platform\OAuth\RootDelegatedAccess;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Enums\ProtocolScope;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;

/**
 * The RFC 9728 document for `https://api.cboxid.com`, served at the API host's
 * `/.well-known/oauth-protected-resource`.
 *
 * The authorization server it names is the platform root's — where the team and the
 * operators sign in, and where an MCP client that knows nothing else starts. A token any
 * Cbox ID environment issued for this resource is accepted as well: its issuer names the
 * environment it acts in.
 */
final readonly class ApiResourceMetadataController
{
    public function __invoke(PlatformRoot $root, IssuerResolver $issuers, RootDelegatedAccess $access): JsonResponse
    {
        $resource = ManagementAudience::api();
        $issuer = $root->run(static fn (): string => $issuers->issuer()) ?? $issuers->issuer();
        $scopes = $access->resource()->scopes ?? [];

        return new JsonResponse([
            'resource' => $resource,
            'authorization_servers' => [rtrim($issuer, '/')],
            'scopes_supported' => array_values(array_unique([...$scopes, ProtocolScope::OfflineAccess->value])),
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Cbox ID API',
            'resource_documentation' => ApiUrls::docs(),
        ], 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_SLASHES);
    }
}
