<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Http\Controllers\Api\Discovery\AuthorizationServerMetadataController;
use App\Support\CliClient;
use Cbox\Id\Api\Support\ServerMetadata;
use Cbox\Id\OAuthServer\Dpop\DpopProofValidator;
use Cbox\Id\OAuthServer\Enums\ProtocolScope;

/**
 * The platform root's RFC 8414 document — written for the root, never the framework's with
 * a few keys removed.
 *
 * An MCP client reads the authorization server from `/mcp`'s resource metadata and fetches
 * this; what it finds here is everything it will do at the root and nothing else. The
 * framework's document describes an identity PROVIDER — OpenID Connect, UserInfo, PAR,
 * CIBA, introspection, token exchange, client-secret auth — and every line of that the root
 * does not serve is an endpoint a conformant client could discover and follow into a 404.
 * So the document is built from what the root DOES serve ({@see RootMcpOAuth}):
 *
 *  - the code flow with PKCE (S256 only), `iss` on the response (RFC 9207);
 *  - public clients only (`none`) — registration is the `mcp` profile, and the CLI is
 *    public too;
 *  - registration while the deployment's mode is `mcp`, client ID metadata documents while
 *    they are on;
 *  - the device grant, for the root's own `cbox` CLI ({@see CliClient}) — the one grant
 *    here a self-registered client cannot hold;
 *  - the scopes of the root's `/mcp` and `offline_access` — no `openid`;
 *  - the key set, because the root signs the tokens it issues.
 *
 * Served on the root's own host while `api.mcp.root_oauth` is on, by
 * {@see AuthorizationServerMetadataController}.
 */
final readonly class RootAuthorizationServerMetadata
{
    public function __construct(private RootMcpOAuth $root) {}

    /**
     * @return array<string, mixed>
     */
    public function document(): array
    {
        $issuer = ServerMetadata::issuer();
        $path = config('cbox-id.oauth.authorization_endpoint_path', '/oauth/authorize');
        $resource = $this->root->resource();

        $scopes = $resource !== null && $resource->dynamicClients ? $resource->scopes : [];
        $scopes = array_values(array_unique([...$scopes, ProtocolScope::OfflineAccess->value]));
        sort($scopes);

        $document = [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/'.ltrim(is_string($path) && $path !== '' ? $path : '/oauth/authorize', '/'),
            'token_endpoint' => $issuer.'/oauth/token',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'device_authorization_endpoint' => $issuer.'/oauth/device_authorization',
            'jwks_uri' => $issuer.'/.well-known/jwks.json',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token', 'urn:ietf:params:oauth:grant-type:device_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => $scopes,
            'authorization_response_iss_parameter_supported' => true,
            'dpop_signing_alg_values_supported' => DpopProofValidator::ALLOWED_ALGS,
        ];

        // The same switch the registration endpoint answers to: the root registers clients in
        // the `mcp` profile only, so in any other mode it registers none.
        if (config('cbox-id.oauth.dynamic_registration.mode') === 'mcp') {
            $document['registration_endpoint'] = $issuer.'/oauth/register';
        }

        if (filter_var(config('cbox-id.oauth.client_id_metadata_documents.enabled', false), FILTER_VALIDATE_BOOL)) {
            $document['client_id_metadata_document_supported'] = true;
        }

        return $document;
    }
}
