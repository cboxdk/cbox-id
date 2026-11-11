<?php

declare(strict_types=1);

namespace App\Platform\Api;

use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * WHO STILL CALLS `/api/v1`, counted per caller: the key's id for a management key, the
 * client id for an access token. The old paths are removed once this counter has stood
 * still for seven days (plan §7.1, 5c), so it has to say who to talk to before then.
 *
 * Labels: `caller_kind` (a closed set), `caller` (a key or client id — bounded by what this
 * deployment issued, never free text from the request) and `route` (the route's pattern,
 * not the path, so ids in it do not multiply series).
 */
final class LegacyApiUsage
{
    public const string METRIC = 'cbox_id_legacy_api_requests_total';

    public static function record(Request $request): void
    {
        [$kind, $caller] = self::caller($request);
        $route = $request->route();

        Telemetry::counter(self::METRIC, 'Requests to the deprecated /api/v1 paths, by caller and route.')
            ->inc(1, [
                'caller_kind' => $kind,
                'caller' => $caller,
                'route' => $route instanceof Route ? $request->method().' /'.ltrim($route->uri(), '/') : $request->method().' (unmatched)',
            ]);
    }

    /** @return array{0: string, 1: string} */
    public static function caller(Request $request): array
    {
        $header = (string) $request->headers->get('Authorization', '');
        $bearer = preg_match('/\A(?:Bearer|DPoP)\s+(\S+)\z/i', trim($header), $match) === 1 ? $match[1] : null;

        return match (CredentialEnvironment::kind($bearer)) {
            'environment_key' => ['environment_key', CredentialEnvironment::keyId((string) $bearer) ?? 'unknown'],
            'organization_key' => ['organization_key', self::organizationKeyId((string) $bearer) ?? 'unknown'],
            'token' => ['client', self::clientOf((string) $bearer)],
            'none' => ['anonymous', 'none'],
            default => ['unknown', 'unknown'],
        };
    }

    private static function organizationKeyId(string $plaintext): ?string
    {
        $id = OrganizationApiKey::query()->where('token_hash', hash('sha256', $plaintext))->value('id');

        return is_string($id) ? $id : null;
    }

    private static function clientOf(string $token): string
    {
        $claims = CredentialEnvironment::claims($token) ?? [];

        foreach (['client_id', 'azp', 'cid'] as $claim) {
            if (is_string($claims[$claim] ?? null) && $claims[$claim] !== '') {
                return mb_substr($claims[$claim], 0, 200);
            }
        }

        return 'unknown';
    }
}
