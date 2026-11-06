<?php

declare(strict_types=1);

namespace Cbox\Id\Billing\Jobs;

use Cbox\Id\Billing\PlanCatalog;
use Cbox\Id\Billing\Subscriptions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncBilling implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $tries = 5;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 60, 120, 300];
    }

    public function handle(PlanCatalog $catalog, Subscriptions $subscriptions): void
    {
        $catalog->sync();
        $subscriptions->sync();
    }
}
