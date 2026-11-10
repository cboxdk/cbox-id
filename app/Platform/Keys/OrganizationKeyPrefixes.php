<?php

declare(strict_types=1);

namespace App\Platform\Keys;

use Cbox\Id\Platform\DatabaseOrganizationApiKeys;

/**
 * ORGANIZATION KEYS ARE STILL MINTED `cbid_ws_` IN v3.0, AND `cbid_org_` IS ALREADY READ.
 *
 * v2 minted organization keys as `cbid_ws_` (the console called the plane a workspace), and
 * v3 calls the plane an organization, whose prefix is the framework's `cbid_org_`. The
 * prefix is part of the hashed secret, so a key cannot be renamed in place — and the
 * consumers read it: the released cbox-cli refuses a `cbid_org_` key as retired. So the
 * prefix moves the way a live contract does:
 *
 *  - v3.0 (expand): mint `cbid_ws_` (config `cbox-id.management_keys.organization_prefix`),
 *    resolve BOTH prefixes;
 *  - once a cbox-cli release that reads `cbid_org_` as an organization key is out, the config
 *    switches minting to `cbid_org_` and `cbid_ws_` keys get a `Deprecation` header;
 *  - `cbid_ws_` is refused only after its use has been zero for seven days.
 *
 * The 1.x `cbid_org_` keys were revoked by `2026_10_08_000100_retire_cbid_org_workspace_keys`
 * and stay revoked: their hash resolves to a revoked row, which refuses.
 */
final class OrganizationKeyPrefixes
{
    /** The prefix v2 minted under, and v3.0 still mints under. */
    public const string WORKSPACE = 'cbid_ws_';

    /** The framework's own prefix, which minting switches to once the CLI reads it. */
    public const string ORGANIZATION = 'cbid_org_';

    /** @return list<string> every prefix an organization key may carry */
    public static function accepted(): array
    {
        return array_values(array_unique([DatabaseOrganizationApiKeys::prefix(), self::WORKSPACE, self::ORGANIZATION]));
    }

    /** Whether $token is an organization key under any accepted prefix. */
    public static function matches(string $token): bool
    {
        return self::prefixOf($token) !== null;
    }

    /** The accepted prefix $token carries, or null. */
    public static function prefixOf(string $token): ?string
    {
        foreach (self::accepted() as $prefix) {
            if (str_starts_with($token, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }
}
