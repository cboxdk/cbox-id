<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Contracts;

use Cbox\Id\Compliance\Sinks\NullAuditExportSink;
use Cbox\Id\Compliance\ValueObjects\AuditExportBatch;

/**
 * The destination the append-only audit trail is exported to. The plugin ships a
 * {@see NullAuditExportSink} default so it is inert until an operator wires a real
 * sink (a JSONL bundle on a disk, or a SIEM endpoint — both via config). The open
 * framework never references a SIEM, object store or column-store directly; it only
 * speaks this contract, so those destinations stay a deployment concern.
 *
 * Delivery is at-least-once and resumable: the export engine advances its cursor
 * only after {@see export()} returns without throwing. An implementation that
 * cannot durably persist a batch MUST throw — the engine then holds the cursor and
 * re-offers the same batch on the next run, rather than silently dropping entries.
 * Implementations SHOULD be idempotent on {@see AuditExportBatch} (scope + sequence
 * range) so a re-offered batch collapses downstream.
 */
interface AuditExportSink
{
    /**
     * Durably export one batch of audit entries. Return normally on success; throw
     * on any failure so the engine can hold its cursor and retry.
     */
    public function export(AuditExportBatch $batch): void;

    /**
     * Whether this sink discards what it is given. The provider asks the SINK rather
     * than checking its concrete class, so a host that binds its own no-op sink is
     * treated the same as the shipped one — and so activation does not depend on
     * knowing every inert implementation by name.
     */
    public function isInert(): bool;
}
