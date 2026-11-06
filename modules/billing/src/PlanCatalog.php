<?php

declare(strict_types=1);

namespace Cbox\Id\Billing;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Id\Billing\Models\BillingPlan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class PlanCatalog
{
    public function __construct(private BillingManagement $billing) {}

    public function sync(): int
    {
        $plans = array_values(array_filter($this->billing->plans(Config::string('billing.currency')),
            fn ($plan): bool => $plan->product === config('billing.product')));
        if ($plans === []) {
            throw new RuntimeException('No Cbox ID plans returned; the existing catalog was preserved.');
        }

        return DB::transaction(function () use ($plans): int {
            // Retired plans stay available to existing subscribers.
            BillingPlan::query()->update(['sold' => false]);
            foreach ($plans as $plan) {
                $old = BillingPlan::query()->find($plan->key);
                $data = [
                    'key' => $plan->key, 'name' => $plan->name, 'price_minor' => $plan->priceMinor,
                    'currency' => $plan->currency, 'interval' => $plan->interval,
                    'features' => $plan->features, 'tax' => $plan->tax?->toArray(),
                ];
                if ($plan->grossMinor() === null && $old !== null
                    && ($old->data['price_minor'] ?? null) === $plan->priceMinor
                    && ($old->data['currency'] ?? null) === $plan->currency) {
                    $data['tax'] = $old->data['tax'] ?? $data['tax'];
                }
                BillingPlan::query()->updateOrCreate(['key' => $plan->key], ['data' => $data, 'sold' => true]);
            }

            return count($plans);
        });
    }

    /** @return array<string, mixed> */
    public function get(string $key, bool $forSale = false): array
    {
        $plan = BillingPlan::query()->whereKey($key)->when($forSale, fn ($q) => $q->where('sold', true))->first();
        if ($plan === null) {
            throw new RuntimeException('The requested plan is absent from the synced catalog.');
        }

        return $plan->data;
    }

    public function environmentLimit(string $key): int
    {
        $data = $this->get($key);
        $features = $data['features'] ?? null;
        $limit = is_array($features) ? ($features[Config::string('billing.environment_feature')] ?? null) : null;
        if (! is_int($limit) || $limit < 0) {
            throw new RuntimeException('The plan has no valid environment allowance.');
        }

        return $limit;
    }
}
