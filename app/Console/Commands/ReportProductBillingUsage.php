<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\ValueObjects\UsageEvent;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** At-least-once delivery with stable event IDs; the central service deduplicates retries. */
final class ReportProductBillingUsage extends Command
{
    protected $signature = 'billing:report-product-usage {--limit=500}';

    protected $description = 'Report durable identity product usage to central billing.';

    public function handle(BillingManagement $billing): int
    {
        $rows = DB::table('product_billing_usage_outbox')->whereNull('reported_at')->orderBy('occurred_at')->limit(max(1, min(1000, (int) $this->option('limit'))))->get();
        $failed = false;
        foreach ($rows as $row) {
            try {
                if (! is_string($row->event_id) || ! is_string($row->meter) || ! is_string($row->workspace_id) || ! is_string($row->occurred_at)) {
                    throw new \UnexpectedValueException('Invalid persisted usage payload.');
                }
                $event = new UsageEvent($row->event_id, $row->meter, 1, new DateTimeImmutable($row->occurred_at), 'cbox-id');
                $receipt = $billing->ingestUsageEvents($row->workspace_id, [$event]);
                if ($receipt->rejected !== []) {
                    throw new \RuntimeException($receipt->rejected[0]->reason);
                }
                DB::table('product_billing_usage_outbox')->where('event_id', $row->event_id)->update(['reported_at' => now(), 'last_error' => null]);
            } catch (Throwable $error) {
                $failed = true;
                DB::table('product_billing_usage_outbox')->where('event_id', $row->event_id)->update(['last_error' => $error::class]);
                report($error);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
