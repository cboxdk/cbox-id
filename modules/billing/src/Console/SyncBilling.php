<?php

declare(strict_types=1);

namespace Cbox\Id\Billing\Console;

use Cbox\Id\Billing\PlanCatalog;
use Cbox\Id\Billing\Subscriptions;
use Illuminate\Console\Command;
use Throwable;

final class SyncBilling extends Command
{
    protected $signature = 'billing:sync-catalog {--subscriptions : Reconcile subscription changes after the catalog}';

    protected $description = 'Synchronize the Cbox ID catalog and optionally project subscriptions';

    public function handle(PlanCatalog $catalog, Subscriptions $subscriptions): int
    {
        try {
            $count = $catalog->sync();
            $this->info("Synchronized {$count} plans.");
            if ($this->option('subscriptions')) {
                $this->info('Synchronized '.$subscriptions->sync().' subscriptions.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            $this->error('Payment synchronization failed; the previous catalog and serving grants were preserved.');

            return self::FAILURE;
        }
    }
}
