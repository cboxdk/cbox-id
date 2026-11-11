<?php

declare(strict_types=1);

namespace App\Platform\Api;

use Illuminate\Http\Request;

/**
 * The hosts the management API is reached on, read from configuration.
 *
 * `api` is where `/v1` lives (`CBOX_ID_API_HOST`, `api.cboxid.com` in production). `app` is
 * the application host (`CBOX_ID_CONSOLE_HOST`), where the one MCP server, the inbound
 * webhooks and the in-cluster `/internal/v1` routes answer.
 */
final class ApiHost
{
    /** The API host's name, lower-cased; null when the deployment has none. */
    public static function name(): ?string
    {
        $host = config('cbox-id.hosts.api');

        return is_string($host) && trim($host) !== '' ? mb_strtolower(trim($host)) : null;
    }

    /** The application host's name, or null when the deployment runs on one host. */
    public static function appName(): ?string
    {
        $host = config('cbox-id.tenancy.account_host');

        return is_string($host) && trim($host) !== '' ? mb_strtolower(trim($host)) : null;
    }

    /** Whether this request arrived on the API host. */
    public static function serves(Request $request): bool
    {
        $host = self::name();

        return $host !== null && mb_strtolower($request->getHost()) === $host;
    }
}
