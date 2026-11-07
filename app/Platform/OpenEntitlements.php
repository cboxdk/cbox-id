<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Authorization\Contracts\EntitlementReader;
use Cbox\Id\Kernel\Authorization\Enums\EnforcementMode;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementValue;
use Illuminate\Contracts\Config\Repository;

/**
 * Live feature defaults, separate from project subscription admission.
 *
 * Open installs grant absent keys. Inclusive hosted installs grant only the configured
 * ID security keys, leaving application entitlements deny-by-default. Metered installs
 * retain the legacy deny-by-default policy. Explicit values always take precedence.
 *
 * This never grants authorization: relationship and environment checks still decide
 * whose data a caller can reach. Defaults contain no numeric limits and are never
 * synthesized into token claims; all() returns only the stored projection.
 */
final class OpenEntitlements implements EntitlementReader
{
    public function __construct(
        private readonly EntitlementReader $inner,
        private readonly Repository $config,
    ) {}

    public function get(string $organizationId, string $key): ?EntitlementValue
    {
        $explicit = $this->inner->get($organizationId, $key);

        if ($explicit !== null || ! $this->included($key)) {
            return $explicit;
        }

        return new EntitlementValue(
            key: $key,
            value: ['enabled' => true],
            mode: EnforcementMode::DecisionApi,
            source: EntitlementSource::System,
            version: 0,
        );
    }

    /** Resolve per call: singleton token issuers may already hold this reader. */
    private function included(string $key): bool
    {
        $mode = $this->config->get('cbox-id.entitlements.mode');

        if ($mode === 'open') {
            return true;
        }

        // Hosted plans include identity security, not arbitrary entitlements belonging
        // to applications using ID. Explicit operational denials still take precedence.
        return $mode === 'inclusive' && $key !== '' && in_array($key, [
            $this->config->get('cbox-id.entitlements.sso'),
            $this->config->get('cbox-id.entitlements.scim'),
            $this->config->get('cbox-id.entitlements.audit_logs'),
        ], true);
    }

    /**
     * @return array<string, EntitlementValue>
     */
    public function all(string $organizationId): array
    {
        return $this->inner->all($organizationId);
    }
}
