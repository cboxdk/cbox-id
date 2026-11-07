<?php

declare(strict_types=1);

namespace App\Platform;

use App\Platform\Billing\ProductBilling;

/** Customer product features are assigned centrally; identity infrastructure has its own reader. */
final class Entitlements
{
    public function __construct(
        private readonly ProductBilling $billing,
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

        return $this->billing->enabled($organizationId, $feature);
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
}
