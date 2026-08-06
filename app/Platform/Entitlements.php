<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Authorization\Contracts\EntitlementReader;

/**
 * The app-layer gate for the enterprise self-serve surfaces. It maps a feature
 * name ('sso' | 'scim') to the configured, namespaced entitlement key and reads
 * the org's live, billing-fed value.
 *
 * Deny-by-default: an unknown feature, an org with no id, or an unset/expired
 * entitlement all resolve to `false`. The entitlement projection itself lives in
 * the `cboxdk/laravel-id` package (billing feeds it); this class only decides
 * which app screens it unlocks.
 */
final class Entitlements
{
    public function __construct(
        private readonly EntitlementReader $reader,
        private readonly CurrentUser $current,
    ) {}

    /**
     * Whether the given organization is entitled to a self-serve feature.
     */
    public function entitled(string $organizationId, string $feature): bool
    {
        if ($organizationId === '') {
            return false;
        }

        $key = $this->keyFor($feature);

        if ($key === null) {
            return false;
        }

        return $this->reader->get($organizationId, $key)?->bool() ?? false;
    }

    /**
     * Whether the currently authenticated org is entitled to a feature. Returns
     * false when there is no active org context (e.g. an unauthenticated request).
     */
    public function entitledOrgFeature(string $feature): bool
    {
        $organizationId = $this->current->organizationId();

        return $organizationId !== null && $this->entitled($organizationId, $feature);
    }

    /**
     * Resolve a feature name to its configured entitlement key, or null when the
     * feature is not one this app gates.
     */
    private function keyFor(string $feature): ?string
    {
        $key = config("cbox-id.entitlements.{$feature}");

        return is_string($key) && $key !== '' ? $key : null;
    }
}
