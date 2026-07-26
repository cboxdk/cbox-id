<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Retention;

use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\Support\AuditScopes;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;

/**
 * Retention for an append-only, hash-chained audit trail — honest by construction.
 *
 * The trail's integrity guarantee (`verifyChain`) and its external anchor (the
 * signed checkpoints) both depend on every recorded entry still being present:
 * deleting or rewriting a row breaks the chain and is exactly what the design is
 * built to detect. So retention here NEVER deletes audit entries. Instead it:
 *
 *   1. Checkpoints each chain — signing and (for an operator to anchor) exporting
 *      the current head, so the retained history stays externally verifiable; and
 *   2. relies on the {@see AuditExportSink} to archive
 *      entries to cold storage (a segment rotation there is an archive concern, not
 *      a delete of the source of truth).
 *
 * {@see apply()} returns a report whose `entriesDeleted` is always 0. The console
 * and docs state this plainly: entries are archived, not erased.
 */
class RetentionPolicy
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly bool $checkpointOnApply = true,
    ) {}

    public function apply(): RetentionReport
    {
        if (! $this->checkpointOnApply) {
            return new RetentionReport([]);
        }

        $checkpointed = [];

        foreach (AuditScopes::all() as $organizationId) {
            $this->audit->checkpoint($organizationId);
            $checkpointed[] = $organizationId ?? '__system__';
        }

        // entriesDeleted stays 0: retention archives + anchors, it does not erase.
        return new RetentionReport($checkpointed);
    }
}
