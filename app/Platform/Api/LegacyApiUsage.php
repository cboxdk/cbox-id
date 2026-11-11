<?php

declare(strict_types=1);

namespace App\Platform\Api;

use Cbox\Id\Platform\Models\OrganizationApiKey;
use Cbox\Telemetry\Facades\Telemetry;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * WHO STILL CALLS `/api/v1`, counted per caller: the key's id for a management key, the
 * client id for an access token. The old paths are removed once this counter has stood
 * still for seven days (plan §7.1, 5c), so it has to say who to talk to before then.
 *
 * Labels: `caller_kind` (a closed set), `caller` (a key or client id — bounded by what this
 * deployment issued, never free text from the request) and `route` (the route's pattern,
 * not the path, so ids in it do not multiply series).
 *
 * The token is read before anything verified it, so its `client_id` is only a claim: a
 * forged one would mint a series per request. It becomes a label only when the request was
 * not refused as unauthenticated AND it names a client this deployment registered; any
 * other token is counted as `unauthenticated` or `unknown`.
 */
final class LegacyApiUsage
{
    public const string METRIC = 'cbox_id_legacy_api_requests_total';

    public static function record(Request $request, ?Response $response = null): void
    {
        [$kind, $caller] = self::caller($request, $response?->getStatusCode() === 401);
        $route = $request->route();

        Telemetry::counter(self::METRIC, 'Requests to the deprecated /api/v1 paths, by caller and route.')
            ->inc(1, [
                'caller_kind' => $kind,
                'caller' => $caller,
                'route' => $route instanceof Route ? $request->method().' /'.ltrim($route->uri(), '/') : $request->method().' (unmatched)',
            ]);
    }

    /** @return array{0: string, 1: string} */
    public static function caller(Request $request, bool $refused = false): array
    {
        $header = (string) $request->headers->get('Authorization', '');
        $bearer = preg_match('/\A(?:Bearer|DPoP)\s+(\S+)\z/i', trim($header), $match) === 1 ? $match[1] : null;

        return match (CredentialEnvironment::kind($bearer)) {
            'environment_key' => ['environment_key', CredentialEnvironment::keyId((string) $bearer) ?? 'unknown'],
            'organization_key' => ['organization_key', self::organizationKeyId((string) $bearer) ?? 'unknown'],
            // A refused token proved nothing about the client it names.
            'token' => ['client', $refused ? 'unauthenticated' : self::clientOf((string) $bearer)],
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
            $clientId = $claims[$claim] ?? null;

            if (is_string($clientId) && $clientId !== '' && mb_strlen($clientId) <= 200) {
                return DB::table('oauth_clients')->where('client_id', $clientId)->exists() ? $clientId : 'unknown';
            }
        }

        return 'unknown';
    }
}
