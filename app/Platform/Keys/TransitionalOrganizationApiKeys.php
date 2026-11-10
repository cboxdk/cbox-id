<?php

declare(strict_types=1);

namespace App\Platform\Keys;

use Cbox\Id\Platform\DatabaseOrganizationApiKeys;
use Cbox\Id\Platform\Models\OrganizationApiKey;

/**
 * The framework's organization keys, resolving a key under either prefix
 * ({@see OrganizationKeyPrefixes}): `cbid_ws_`, which v2 and v3.0 mint, and `cbid_org_`,
 * which minting switches to once the CLI reads it.
 *
 * The framework checks the configured prefix before it hashes, and nothing else differs: a
 * key is the same row, looked up by the hash of the same plaintext. So a token under the
 * other prefix is resolved with that prefix configured for that one call, and every other
 * rule — revoked, expired, a suspended organization, the throttled `last_used_at` — is the
 * framework's own, unchanged.
 */
final class TransitionalOrganizationApiKeys extends DatabaseOrganizationApiKeys
{
    private const string CONFIG = 'cbox-id.management_keys.organization_prefix';

    public function resolve(string $plaintext): ?OrganizationApiKey
    {
        $prefix = OrganizationKeyPrefixes::prefixOf($plaintext);

        if ($prefix === null || $prefix === self::prefix()) {
            return parent::resolve($plaintext);
        }

        $configured = config(self::CONFIG);
        config([self::CONFIG => $prefix]);

        try {
            return parent::resolve($plaintext);
        } finally {
            config([self::CONFIG => $configured]);
        }
    }
}
