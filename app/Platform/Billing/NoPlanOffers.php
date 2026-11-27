<?php

declare(strict_types=1);

namespace App\Platform\Billing;

/** A deployment that does not bill: no plans to choose, nothing to confirm. */
final readonly class NoPlanOffers implements PlanOffers
{
    public function forOrganization(string $organizationId): array
    {
        return [];
    }

    public function reviewUrl(string $projectId, string $plan): ?string
    {
        return null;
    }
}
