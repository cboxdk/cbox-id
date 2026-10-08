<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use App\Models\AuditLogs\AuditLogChain;
use App\Models\AuditLogs\AuditLogEvent;
use App\Models\AuditLogs\AuditLogEventTarget;
use App\Models\AuditLogs\AuditLogExport;
use Illuminate\Support\Carbon;

/**
 * Applies an environment's retention to its audit events, and clears away its expired
 * exports. Runs inside ONE environment — the scheduled command enters each in turn — so
 * every query here is held to it by the hard scope.
 *
 * RETENTION COUNTS FROM RECEIPT. An event is kept `retention_days` from when it arrived,
 * not from the `occurred_at` its sender wrote: a backfill of last year's events is kept for
 * the retention period like any other, and — the reason that decides it — the chain is in
 * arrival order, so cutting by arrival removes a PREFIX of it. Cutting by `occurred_at`
 * would punch holes in the middle of a chain wherever events arrived out of order, and a
 * chain with holes cannot be verified.
 *
 * The cut is recorded on the chain head before a row is deleted ({@see AuditLogChains::cut()}):
 * verification then starts after it and checks the oldest kept event still points at the
 * last removed one. A prune that dies half-way leaves rows that are merely ignored.
 */
final readonly class AuditLogPruner
{
    private const int CHUNK = 1000;

    public function __construct(
        private AuditLogChains $chains,
        private AuditLogPolicy $policy,
    ) {}

    /**
     * @return array{events: int, exports: int}
     */
    public function prune(): array
    {
        $cutoff = Carbon::now()->subDays($this->policy->retentionDays());
        $events = 0;

        AuditLogChain::query()->orderBy('id')->chunkById(200, function ($chains) use ($cutoff, &$events): void {
            /** @var AuditLogChain $chain */
            foreach ($chains as $chain) {
                $events += $this->pruneChain($chain, $cutoff);
            }
        });

        return ['events' => $events, 'exports' => $this->expireExports()];
    }

    private function pruneChain(AuditLogChain $chain, Carbon $cutoff): int
    {
        // The newest event received before the cutoff, off the (organization, received)
        // index: one row. Everything up to its sequence goes.
        $last = AuditLogEvent::query()
            ->where('organization_id', $chain->organization_id)
            ->where('created_at', '<', $cutoff)
            ->orderByDesc('created_at')
            ->orderByDesc('sequence')
            ->first(['id', 'sequence', 'hash']);

        if ($last === null || $last->sequence <= $chain->pruned_through_sequence) {
            return 0;
        }

        $this->chains->cut($chain, $last->sequence, $last->hash);
        $deleted = 0;

        do {
            $ids = AuditLogEvent::query()
                ->where('organization_id', $chain->organization_id)
                ->where('sequence', '<=', $last->sequence)
                ->orderBy('sequence')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            AuditLogEventTarget::query()->whereIn('event_id', $ids)->delete();
            $removed = AuditLogEvent::query()->whereIn('id', $ids)->delete();
            $deleted += is_int($removed) ? $removed : 0;
        } while (count($ids) === self::CHUNK);

        return $deleted;
    }

    /** Exports past their expiry: the file deleted, the row kept as `expired` a while longer. */
    private function expireExports(): int
    {
        $expired = 0;

        AuditLogExport::query()
            ->where('state', AuditLogExport::READY)
            ->where('expires_at', '<', Carbon::now())
            ->orderBy('id')
            ->chunkById(200, function ($exports) use (&$expired): void {
                /** @var AuditLogExport $export */
                foreach ($exports as $export) {
                    if ($export->path !== null) {
                        AuditLogExports::disk()->delete($export->path);
                    }

                    $export->forceFill(['state' => AuditLogExport::EXPIRED, 'path' => null])->save();
                    $expired++;
                }
            });

        // A record of an export is kept as long as the events could have been; after that it
        // describes nothing.
        AuditLogExport::query()
            ->where('created_at', '<', Carbon::now()->subDays($this->policy->retentionDays()))
            ->delete();

        return $expired;
    }
}
