<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use App\Models\AuditLogs\AuditLogEvent;
use App\Platform\AuditLogs\AuditLogChains;

/**
 * One audit event an app sent — `AuditLogEvent` in the spec.
 *
 * Unlike the platform's own trail ({@see AuditEntryResource}), the chain is returned with
 * every event: these are the CUSTOMER's records, held on their behalf, and the hashes are
 * how they can check for themselves that nothing was changed after it arrived. Everything
 * in {@see self::hashed()} is what the hash covers, in exactly this shape.
 */
final class AuditLogEventResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(AuditLogEvent $event): array
    {
        return [
            ...self::hashed($event),
            'schema_version' => $event->schema_version,
            'received_at' => Timestamp::of($event->created_at),
            'prev_hash' => $event->prev_hash,
            'hash' => $event->hash,
        ];
    }

    /**
     * The part of an event its hash covers ({@see AuditLogChains::hash()}): what the sender
     * said, plus where in the chain it sits.
     *
     * @return array<string, mixed>
     */
    public static function hashed(AuditLogEvent $event): array
    {
        return self::document(
            $event->id,
            $event->organization_id,
            $event->sequence,
            $event->action,
            $event->occurredAtIso(),
            [
                'id' => $event->actor_id,
                'type' => $event->actor_type,
                'name' => $event->actor_name,
                'metadata' => $event->actor_metadata,
            ],
            $event->targets,
            ['location' => $event->location, 'user_agent' => $event->user_agent],
            $event->metadata,
        );
    }

    /**
     * The hashed document, built from its parts — the one shape both an event being
     * appended and an event being verified are hashed in.
     *
     * @param  array<string, mixed>  $actor
     * @param  list<array<string, mixed>>  $targets
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>
     */
    public static function document(string $id, string $organizationId, int $sequence, string $action, string $occurredAt, array $actor, array $targets, array $context, ?array $metadata): array
    {
        return [
            'id' => $id,
            'organization_id' => $organizationId,
            'sequence' => $sequence,
            'action' => $action,
            'occurred_at' => $occurredAt,
            'actor' => [
                'id' => $actor['id'] ?? null,
                'type' => $actor['type'] ?? null,
                'name' => $actor['name'] ?? null,
                'metadata' => self::map($actor['metadata'] ?? null),
            ],
            'targets' => array_map(static fn (array $target): array => [
                'id' => $target['id'] ?? null,
                'type' => $target['type'] ?? null,
                'name' => $target['name'] ?? null,
                'metadata' => self::map($target['metadata'] ?? null),
            ], $targets),
            'context' => [
                'location' => $context['location'] ?? null,
                'user_agent' => $context['user_agent'] ?? null,
            ],
            'metadata' => self::map($metadata),
        ];
    }

    /**
     * Metadata as it is hashed and returned: an object, or null for none — never `[]`, which
     * would encode as a JSON array.
     *
     * @return array<mixed>|null
     */
    private static function map(mixed $metadata): ?array
    {
        return is_array($metadata) && $metadata !== [] ? $metadata : null;
    }
}
