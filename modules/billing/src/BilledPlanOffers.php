<?php

declare(strict_types=1);

namespace Cbox\Id\Billing;

use App\Platform\Billing\PlanOffers;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Id\Platform\Models\Project;
use Throwable;

/**
 * The plans Cbox Billing sells for this product, read the way the billing page reads them,
 * so the create form and the billing page never offer two different lists.
 */
final readonly class BilledPlanOffers implements PlanOffers
{
    public function __construct(private BillingManagement $billing) {}

    public function forOrganization(string $organizationId): array
    {
        if (config('billing.enabled') !== true || ! config('billing-client.base_url') || ! config('billing-client.api_token')) {
            return [];
        }

        try {
            $plans = $this->billing->plans(null, $organizationId);
        } catch (Throwable $error) {
            // Creating a platform must not depend on the catalog answering: without it the
            // form offers no choice and the platform starts on the free allowance.
            report($error);

            return [];
        }

        $offers = [];

        foreach ($plans as $plan) {
            if ($plan->product !== config('billing.product')) {
                continue;
            }

            $environments = $plan->features['cbox-id.environments'] ?? null;

            $offers[] = [
                'key' => $plan->key,
                'name' => $plan->name,
                'priceMinor' => $plan->priceMinor,
                'totalMinor' => $plan->grossMinor(),
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'environments' => is_int($environments) ? $environments : null,
            ];
        }

        usort($offers, static fn (array $a, array $b): int => $a['priceMinor'] <=> $b['priceMinor']);

        return $offers;
    }

    public function reviewUrl(string $projectId, string $plan): ?string
    {
        $organization = Project::query()->whereKey($projectId)->value('organization_id');

        return is_string($organization)
            ? route('orgs.settings.billing', ['organization' => $organization, 'review' => $projectId, 'plan' => $plan])
            : null;
    }
}
