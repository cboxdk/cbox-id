<?php

declare(strict_types=1);

namespace App\Platform\Billing;

/**
 * The plans an organization may put a new identity platform on, and where it confirms one.
 *
 * Asked by the create form, which shows the plans and their prices before anything is made,
 * and by the create action's controller, which checks the chosen key against the same list.
 * Money never moves here: a paid plan is confirmed on the billing page's own review, which
 * re-prices it and asks for consent ({@see reviewUrl()}).
 *
 * The host's answer is {@see NoPlanOffers}: a deployment that does not bill has nothing to
 * choose. The billing module binds its own.
 */
interface PlanOffers
{
    /**
     * Cheapest first. Empty when the deployment does not bill or the catalog cannot be read:
     * the form then creates the platform on the free allowance, as it always did.
     *
     * @return list<array{key: string, name: string, priceMinor: int, totalMinor: int|null, currency: string, interval: string, environments: int|null}>
     */
    public function forOrganization(string $organizationId): array;

    /** Where the person reviews and confirms `$plan` for the new platform, or null without one. */
    public function reviewUrl(string $projectId, string $plan): ?string;
}
