<?php

declare(strict_types=1);

namespace App\Platform\Api;

use App\Mcp\McpProtectedResources;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Support\Facades\Log;

/**
 * A client asked the token endpoint for an issuer's own `/mcp` — the legacy management
 * audience. It is still granted, exactly as before; this only records who asked, so the
 * audience is retired when nobody does (plan §2.5 rule 4).
 */
final class LegacyAudienceRequest
{
    public const string METRIC = 'cbox_id_legacy_resource_requests_total';

    public static function recordIfLegacy(Client $client, ?string $resource): void
    {
        if ($resource === null || $resource === '') {
            return;
        }

        $issuer = rtrim(app(IssuerResolver::class)->issuer(), '/');

        if (rtrim($resource, '/') !== $issuer.McpProtectedResources::PATH || in_array(rtrim($resource, '/'), ManagementAudience::current(), true)) {
            return;
        }

        Telemetry::counter(self::METRIC, 'Token requests that named an issuer\'s own /mcp, the legacy management audience, by client.')
            ->inc(1, ['client_id' => (string) $client->client_id]);

        Log::notice('legacy_resource_requested', [
            'client_id' => $client->client_id,
            'resource' => $resource,
            'instead' => ManagementAudience::api(),
        ]);
    }
}
