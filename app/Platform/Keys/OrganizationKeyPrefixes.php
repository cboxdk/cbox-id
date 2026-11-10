<?php

declare(strict_types=1);

namespace App\Platform\Keys;

use Cbox\Id\Platform\DatabaseOrganizationApiKeys;
use Cbox\Id\Platform\Models\OrganizationApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ORGANIZATION KEYS ARE `cbid_org_` AGAIN, AND `cbid_ws_` STILL WORKS UNTIL v3.1.
 *
 * v2 minted organization keys as `cbid_ws_` (the console called the plane a workspace).
 * v3 calls it an organization, so new keys are minted under the framework's own
 * `cbid_org_`. The prefix is part of the hashed secret, so a `cbid_ws_` key cannot be
 * renamed in place — and ID is live: every holder of one keeps working, with a
 * `Deprecation` header on each response and a log line per use, so telemetry names who still
 * has to rotate before v3.1 refuses them.
 *
 * The 1.x `cbid_org_` keys were revoked by `2026_10_08_000100_retire_cbid_org_workspace_keys`
 * and stay revoked: reusing the prefix resolves their hash to a revoked row, which refuses.
 */
final class OrganizationKeyPrefixes
{
    /** The prefix v2 minted under, accepted until v3.1. */
    public const string LEGACY = 'cbid_ws_';

    /** When the legacy prefix was deprecated (RFC 9745 `Deprecation`: `@<epoch seconds>`). */
    public const string DEPRECATED_AT = '@1794268800';

    private const string ATTRIBUTE = 'cbox-id.legacy-organization-key';

    /** Whether $token is an organization key under either prefix. */
    public static function matches(string $token): bool
    {
        return str_starts_with($token, DatabaseOrganizationApiKeys::prefix()) || self::isLegacy($token);
    }

    public static function isLegacy(string $token): bool
    {
        return str_starts_with($token, self::LEGACY);
    }

    /** Remember that this request authenticated with a legacy key, and log who holds it. */
    public static function used(OrganizationApiKey $key, ?Request $request = null): void
    {
        $request ??= request();
        $request->attributes->set(self::ATTRIBUTE, true);

        Log::notice('A cbid_ws_ organization key was used; it stops working in v3.1.', [
            'key_id' => $key->id,
            'organization_id' => $key->organization_id,
            'path' => $request->path(),
        ]);
    }

    /** Mark $response deprecated when the request authenticated with a legacy key. */
    public static function mark(Request $request, Response $response): Response
    {
        if ($request->attributes->get(self::ATTRIBUTE) === true) {
            $response->headers->set('Deprecation', self::DEPRECATED_AT);
            $response->headers->set('Link', '<https://github.com/cboxdk/cbox-id/blob/main/UPGRADING.md#organization-keys-are-cbid_org_>; rel="deprecation"', false);
        }

        return $response;
    }
}
