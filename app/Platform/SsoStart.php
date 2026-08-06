<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;

/**
 * Resolves the browser entry point for an SSO connection's inbound login flow.
 *
 * The framework (cboxdk/laravel-id) registers these routes by controller, not by
 * name, so home-realm discovery builds the URL from the connection id. OIDC uses
 * the RP-initiated redirect; SAML uses the SP-initiated login (AuthnRequest).
 */
final class SsoStart
{
    public static function url(Connection $connection): string
    {
        // A match, not a ternary. The ternary read "OIDC or else SAML", so adding OAuth 2.0
        // would have sent every GitHub and Discord button to the SAML SP-initiated login —
        // a URL that exists, answers, and fails in a way that looks like a broken provider
        // rather than a wrong link. An enum with a new case should break loudly here.
        return match ($connection->type) {
            ConnectionType::Oidc => url("/sso/oidc/{$connection->id}/redirect"),
            ConnectionType::OAuth2 => url("/sso/oauth2/{$connection->id}/redirect"),
            ConnectionType::Saml => url("/sso/saml/{$connection->id}/login"),
        };
    }
}
