<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\ValueObjects;

use Cbox\Id\Compliance\Contracts\AuditExportSink;

/**
 * One contiguous slice of a single chain's audit trail, handed to an
 * {@see AuditExportSink}. It carries the scope it
 * belongs to and the sequence range it covers, so a sink can dedupe idempotently
 * (scope + `[fromSequence, toSequence]`) and a reader can confirm continuity.
 */
final readonly class AuditExportBatch
{
    /**
     * @param  list<AuditExportRecord>  $records
     */
    public function __construct(
        public string $scope,
        public ?string $organizationId,
        public array $records,
        public int $fromSequence,
        public int $toSequence,
    ) {}

    public function count(): int
    {
        return count($this->records);
    }

    /**
     * The batch as a plain array (its records flattened), for a JSON body or line.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'organization_id' => $this->organizationId,
            'from_sequence' => $this->fromSequence,
            'to_sequence' => $this->toSequence,
            'records' => array_map(static fn (AuditExportRecord $r): array => $r->toArray(), $this->records),
        ];
    }
}
