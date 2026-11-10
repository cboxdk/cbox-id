<?php

declare(strict_types=1);

namespace App\Platform\Keys;

use Cbox\Id\Platform\DatabaseOrganizationApiKeys;
use Cbox\Id\Platform\Models\OrganizationApiKey;

/**
 * The framework's organization keys, also resolving a `cbid_ws_` key minted before v3
 * ({@see OrganizationKeyPrefixes}).
 *
 * The framework checks the configured prefix before it hashes, and nothing else differs: a
 * legacy key is the same row, looked up by the hash of the same plaintext. So a legacy
 * token is resolved with the legacy prefix configured for that one call, and every other
 * rule — revoked, expired, a suspended organization, the throttled `last_used_at` — is the
 * framework's own, unchanged.
 */
final class TransitionalOrganizationApiKeys extends DatabaseOrganizationApiKeys
{
    private const string CONFIG = 'cbox-id.management_keys.organization_prefix';

    public function resolve(string $plaintext): ?OrganizationApiKey
    {
        if (! OrganizationKeyPrefixes::isLegacy($plaintext) || str_starts_with($plaintext, self::prefix())) {
            return parent::resolve($plaintext);
        }

        $configured = config(self::CONFIG);
        config([self::CONFIG => OrganizationKeyPrefixes::LEGACY]);

        try {
            $key = parent::resolve($plaintext);
        } finally {
            config([self::CONFIG => $configured]);
        }

        if ($key !== null) {
            OrganizationKeyPrefixes::used($key);
        }

        return $key;
    }
}
