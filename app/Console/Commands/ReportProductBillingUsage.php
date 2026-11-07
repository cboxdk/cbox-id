<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\ValueObjects\UsageEvent;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * At-least-once delivery with stable event IDs; the central service deduplicates retries.
 *
 * Rows go one batch per workspace. A rejected row keeps Billing's reason and stays pending,
 * and rows that have never failed are sent first, so a stuck row cannot hold back the rest.
 */
final class ReportProductBillingUsage extends Command
{
    protected $signature = 'billing:report-product-usage {--limit=1000}';

    protected $description = 'Report durable identity product usage to central billing.';

    public function handle(BillingManagement $billing): int
    {
        $rows = DB::table('product_billing_usage_outbox')->whereNull('reported_at')
            ->orderByRaw('case when last_error is null then 0 else 1 end')->orderBy('occurred_at')
            ->limit(max(1, min(5000, (int) $this->option('limit'))))->get();
        $failed = false;
        foreach ($rows->groupBy('workspace_id') as $workspace => $pending) {
            foreach ($pending->chunk(1000) as $chunk) {
                $chunk = $chunk->values();
                try {
                    $events = array_values($chunk->map(static fn (\stdClass $row): UsageEvent => self::event($row))->all());
                    $receipt = $billing->ingestUsageEvents((string) $workspace, $events);
                } catch (Throwable $error) {
                    $failed = true;
                    DB::table('product_billing_usage_outbox')->whereIn('event_id', $chunk->pluck('event_id'))
                        ->update(['last_error' => $error::class]);
                    report($error);

                    continue;
                }
                $rejected = [];
                foreach ($receipt->rejected as $rejection) {
                    $rejected[$rejection->id] = $rejection->reason;
                }
                foreach ($rejected as $id => $reason) {
                    DB::table('product_billing_usage_outbox')->where('event_id', $id)->update(['last_error' => $reason]);
                }
                $delivered = $chunk->pluck('event_id')->reject(static fn (string $id): bool => isset($rejected[$id]))->values();
                foreach ($delivered->chunk(500) as $ids) {
                    DB::table('product_billing_usage_outbox')->whereIn('event_id', $ids->all())
                        ->update(['reported_at' => now(), 'last_error' => null]);
                }
                if ($rejected !== []) {
                    $failed = true;
                    report(new \RuntimeException(sprintf('Billing rejected %d usage event(s): %s', count($rejected),
                        implode(', ', array_unique(array_values($rejected))))));
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private static function event(\stdClass $row): UsageEvent
    {
        $id = $row->event_id ?? null;
        $meter = $row->meter ?? null;
        $occurredAt = $row->occurred_at ?? null;
        if (! is_string($id) || ! is_string($meter) || ! is_string($row->workspace_id ?? null) || ! is_string($occurredAt)) {
            throw new \UnexpectedValueException('Invalid persisted usage payload.');
        }
        $value = $row->value ?? 1;
        $value = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 1);
        $dimensions = [];
        $stored = $row->dimensions ?? null;
        $decoded = is_string($stored) ? json_decode($stored, true, 4, JSON_THROW_ON_ERROR) : null;
        foreach (is_array($decoded) ? $decoded : [] as $key => $dimension) {
            if (is_string($key) && is_string($dimension)) {
                $dimensions[$key] = $dimension;
            }
        }
        $uniqueKey = $row->unique_key ?? null;

        return new UsageEvent($id, $meter, max(0, $value), new DateTimeImmutable($occurredAt), 'cbox-id',
            $dimensions, is_string($uniqueKey) ? $uniqueKey : null);
    }
}
