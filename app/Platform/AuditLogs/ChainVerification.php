<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

/**
 * What re-hashing an organization's audit-event chain found ({@see AuditLogChains::verify()}).
 *
 * `reason` names the first break: `missing` (a sequence is absent), `link` (an event does
 * not point at the one before it), `hash` (an event's content no longer matches its hash)
 * or `truncated` (the chain head says it goes further than the events do). `complete` is
 * false when the check stopped at its limit before reaching the head — continue from
 * `last_sequence + 1`.
 */
final readonly class ChainVerification
{
    public function __construct(
        public bool $valid,
        public int $verifiedCount,
        public ?int $firstSequence,
        public ?int $lastSequence,
        public int $headSequence,
        public ?int $brokenAtSequence,
        public ?string $reason,
        public bool $complete,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(string $organizationId): array
    {
        return [
            'organization_id' => $organizationId,
            'valid' => $this->valid,
            'complete' => $this->complete,
            'verified_count' => $this->verifiedCount,
            'first_sequence' => $this->firstSequence,
            'last_sequence' => $this->lastSequence,
            'head_sequence' => $this->headSequence,
            'broken_at_sequence' => $this->brokenAtSequence,
            'reason' => $this->reason,
        ];
    }
}
