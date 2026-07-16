<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Dsr;

use Cbox\Id\Compliance\ValueObjects\AuditExportRecord;

/**
 * A data-subject's compliance-relevant records, aggregated for a GDPR/DSR access
 * request. v0.1 covers the subject's audit trail (actions they performed, across
 * every chain), projected as tamper-evidence-preserving {@see AuditExportRecord}s.
 *
 * The bundle is machine-readable (portable JSON). It intentionally does NOT offer
 * erasure — see {@see SubjectDataExport} for why that is scoped out honestly.
 */
final readonly class SubjectDataBundle
{
    /**
     * @param  list<AuditExportRecord>  $auditTrail
     */
    public function __construct(
        public string $subjectId,
        public array $auditTrail,
    ) {}

    public function auditEntryCount(): int
    {
        return count($this->auditTrail);
    }

    /**
     * The portable, JSON-serializable representation of the subject's data.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject_id' => $this->subjectId,
            'generated_at' => now()->toIso8601String(),
            'audit_trail' => array_map(
                static fn (AuditExportRecord $record): array => $record->toArray(),
                $this->auditTrail,
            ),
        ];
    }
}
