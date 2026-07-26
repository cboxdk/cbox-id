<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\ValueObjects;

use Carbon\CarbonInterface;
use Cbox\Id\Analytics\Contracts\ReportSink;

/**
 * The immutable analytics projection of a single delivered domain event — the unit
 * a {@see ReportSink} writes.
 *
 * It carries no raw payload: only the event's identity, its type and mapped usage
 * metric, its tenancy (organization + environment), when it happened, and a digest
 * of the payload (so downstream can detect a changed payload without storing PII).
 * `eventId` is the natural, stable key — sinks dedupe on it so at-least-once
 * outbox re-delivery collapses to one row.
 */
readonly class ReportRecord
{
    public function __construct(
        public string $eventId,
        public string $type,
        public ?string $organizationId,
        public ?string $environmentId,
        public ?string $metric,
        public CarbonInterface $occurredAt,
        public string $payloadDigest,
    ) {}

    /**
     * The flat, sink-agnostic row shape (empty string for absent tenancy/metric,
     * matching the ClickHouse schema's non-nullable columns).
     *
     * @return array<string, string>
     */
    public function toRow(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type,
            'metric' => $this->metric ?? '',
            'organization_id' => $this->organizationId ?? '',
            'environment_id' => $this->environmentId ?? '',
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'payload_digest' => $this->payloadDigest,
        ];
    }
}
