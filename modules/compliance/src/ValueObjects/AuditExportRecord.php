<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\ValueObjects;

use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;

/**
 * The immutable, sink-agnostic projection of a single audit entry — the unit a
 * {@see AuditExportSink} ships.
 *
 * It mirrors the entry's tamper-evidence fields verbatim (`sequence`, `prevHash`,
 * `hash`), so a downstream SIEM or cold archive can re-verify the hash chain
 * independently of the platform database. The record is a flat, JSON-serializable
 * shape decoupled from the Eloquent model.
 */
readonly class AuditExportRecord
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $id,
        public string $scope,
        public ?string $organizationId,
        public int $sequence,
        public string $actorType,
        public ?string $actorId,
        public string $action,
        public ?string $targetType,
        public ?string $targetId,
        public array $context,
        public ?string $ip,
        public string $prevHash,
        public string $hash,
        public ?string $recordedAt,
    ) {}

    public static function fromEntry(AuditEntry $entry): self
    {
        return new self(
            id: $entry->id,
            scope: $entry->scope,
            organizationId: $entry->organization_id,
            sequence: $entry->sequence,
            actorType: $entry->actor_type->value,
            actorId: $entry->actor_id,
            action: $entry->action,
            targetType: $entry->target_type,
            targetId: $entry->target_id,
            context: $entry->context,
            ip: $entry->ip,
            prevHash: $entry->prev_hash,
            hash: $entry->hash,
            recordedAt: $entry->recorded_at?->toIso8601String(),
        );
    }

    /**
     * The flat, ordered row shape written to a JSONL bundle or posted to a SIEM.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'organization_id' => $this->organizationId,
            'sequence' => $this->sequence,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'action' => $this->action,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'context' => $this->context,
            'ip' => $this->ip,
            'prev_hash' => $this->prevHash,
            'hash' => $this->hash,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
