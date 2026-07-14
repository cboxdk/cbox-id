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
        return $connection->type === ConnectionType::Oidc
            ? url("/sso/oidc/{$connection->id}/redirect")
            : url("/sso/saml/{$connection->id}/login");
    }
}
