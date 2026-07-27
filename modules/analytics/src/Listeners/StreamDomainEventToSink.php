<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Listeners;

use Cbox\Id\Analytics\Contracts\ReportSink;
use Cbox\Id\Analytics\ValueObjects\ReportRecord;
use Cbox\Id\Kernel\Events\EventDelivered;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Usage\EventMetricMap;
use Throwable;

/**
 * The hook that makes this a plugin: it listens on the platform's public
 * {@see EventDelivered} seam (dispatched by the transactional outbox relay) and
 * projects each delivered event into a {@see ReportRecord} on the bound
 * {@see ReportSink}. No emit site is touched; new analytics is a listener, not an
 * edit to the framework.
 *
 * It fails open — any error building or writing the record is swallowed, because
 * analytics must never be what breaks event delivery — and it is idempotent by
 * construction: the record's `eventId` lets the sink collapse the at-least-once
 * re-deliveries the outbox is allowed to make.
 */
class StreamDomainEventToSink
{
    public function __construct(
        private readonly ReportSink $sink,
        private readonly EnvironmentContext $environments,
    ) {}

    public function handle(EventDelivered $delivered): void
    {
        try {
            $event = $delivered->event;

            $record = new ReportRecord(
                eventId: $event->id,
                type: $event->type,
                organizationId: $event->organization_id,
                environmentId: $this->currentEnvironment(),
                metric: EventMetricMap::for($event->type)?->value,
                occurredAt: $event->occurred_at,
                payloadDigest: hash('sha256', json_encode($event->payload, JSON_THROW_ON_ERROR)),
            );

            $this->sink->write([$record]);
        } catch (Throwable) {
            // Fail open: analytics streaming must never break event delivery.
        }
    }

    /**
     * The outbox row carries no environment column, so environment attribution is
     * best-effort: resolved from the current context if one is active, else null.
     */
    private function currentEnvironment(): ?string
    {
        try {
            return $this->environments->current()?->environmentKey();
        } catch (Throwable) {
            return null;
        }
    }
}
