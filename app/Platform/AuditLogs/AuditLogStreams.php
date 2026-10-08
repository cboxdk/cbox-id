<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use App\Models\AuditLogs\AuditLogEvent;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\LaravelSiem\Contracts\StreamDispatcher;
use Cbox\Siem\Enums\EventCategory;
use Cbox\Siem\Enums\Outcome;
use Cbox\Siem\Enums\Severity;
use Cbox\Siem\ValueObjects\Party;
use Cbox\Siem\ValueObjects\SiemEvent;
use DateTimeImmutable;

/**
 * An organization's audit events, carried to the SIEM streams THAT ORGANIZATION OWNS.
 *
 * WHY STREAMS AND NOT A WEBHOOK. A webhook per audit event would be a second copy of every
 * event pushed at the app that just sent it — volume nobody asked for, to the one party
 * that already has the data. The party that does NOT have it is the customer, whose
 * security team wants its own audit events in its own SIEM; that is what a log stream an
 * organization owns already is (created on its console), and the delivery machinery —
 * transactional outbox, at-least-once, per-stream action filter and redaction, the
 * destinations' formats — is laravel-siem's, unchanged.
 *
 * ONLY THE ORGANIZATION'S OWN STREAMS. An environment-wide stream is the operator's
 * shipping of the platform's trail; mixing every customer's app events into it would
 * multiply its volume by however chatty the app is, for data the operator's app already
 * holds. A stream's action filter applies as to everything else it carries, and every
 * event says where it came from (`source: app`), so a SIEM can tell an app's
 * `user.created` from the platform's.
 *
 * Called inside the ingest transaction: the outbox rows commit with the events or not at
 * all, and nothing is sent synchronously.
 */
final readonly class AuditLogStreams
{
    public function __construct(private StreamDispatcher $dispatcher) {}

    /**
     * @param  list<AuditLogEvent>  $events  All of one organization's.
     */
    public function dispatch(string $organizationId, array $events): void
    {
        /** @var list<AuditStream> $streams */
        $streams = AuditStream::query()
            ->where('enabled', true)
            ->ownedByOrganization($organizationId)
            ->get()
            ->all();

        if ($streams === []) {
            return;
        }

        foreach ($events as $event) {
            $this->dispatcher->dispatch(self::toSiemEvent($event), $streams);
        }
    }

    public static function toSiemEvent(AuditLogEvent $event): SiemEvent
    {
        /** @var array<string, mixed>|null $target */
        $target = $event->targets[0] ?? null;
        $targetType = is_array($target) && is_string($target['type'] ?? null) ? $target['type'] : null;
        $targetId = is_array($target) && is_string($target['id'] ?? null) ? $target['id'] : null;

        $context = [
            'source' => 'app',
            'organization_id' => $event->organization_id,
            'sequence' => $event->sequence,
            'hash' => $event->hash,
            'prev_hash' => $event->prev_hash,
            'actor_name' => $event->actor_name,
            'user_agent' => $event->user_agent,
            'targets' => json_encode($event->targets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
        ];

        foreach ([...self::flat('metadata', $event->metadata), ...self::flat('actor.metadata', $event->actor_metadata)] as $key => $value) {
            $context[$key] = $value;
        }

        return new SiemEvent(
            id: $event->id,
            occurredAt: new DateTimeImmutable($event->occurredAtIso()),
            action: $event->action,
            category: EventCategory::Audit,
            outcome: Outcome::Success,
            severity: Severity::Info,
            actor: Party::of($event->actor_type, $event->actor_id),
            target: $targetType !== null && $targetId !== null ? Party::of($targetType, $targetId) : null,
            sourceIp: is_string($event->location) && filter_var($event->location, FILTER_VALIDATE_IP) !== false ? $event->location : null,
            context: $context,
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, scalar|null>
     */
    private static function flat(string $prefix, ?array $metadata): array
    {
        $flat = [];

        foreach ($metadata ?? [] as $key => $value) {
            $flat["{$prefix}.{$key}"] = is_scalar($value) || $value === null ? $value : (json_encode($value) ?: null);
        }

        return $flat;
    }
}
