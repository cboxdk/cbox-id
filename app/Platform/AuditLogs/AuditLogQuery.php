<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use App\Models\AuditLogs\AuditLogEvent;
use App\Models\AuditLogs\AuditLogEventTarget;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reading audit events: filtered, newest first (or oldest), a page at a time by KEYSET.
 *
 * The order is `(occurred_at, id)` — when the sender says it happened, ties broken by the
 * event's id — and a page continues from the last row of the one before, never from an
 * offset: an offset re-reads every row it skips, which on an organization with a year of
 * events is the whole year, every page. Every filter lands on an index
 * (see the migration): an organization's events by time, by action, an actor's, a
 * target's through its own table.
 *
 * The cursor is opaque to callers — base64url of the last row's time and id — and carries
 * nothing a caller could use to reach rows their filters do not: it only says where in
 * THIS filtered list to continue.
 */
final class AuditLogQuery
{
    /**
     * @return Builder<AuditLogEvent>
     */
    public static function filtered(AuditLogFilters $filters): Builder
    {
        $query = AuditLogEvent::query();

        if ($filters->organizationId !== null) {
            $query->where('organization_id', $filters->organizationId);
        }

        if ($filters->actions !== []) {
            $query->whereIn('action', $filters->actions);
        }

        if ($filters->actorId !== null) {
            $query->where('actor_id', $filters->actorId);
        }

        if ($filters->targetId !== null) {
            $targets = AuditLogEventTarget::query()->select('event_id')->where('target_id', $filters->targetId);

            if ($filters->organizationId !== null) {
                $targets->where('organization_id', $filters->organizationId);
            }

            $query->whereIn('id', $targets->toBase());
        }

        if ($filters->rangeStart !== null) {
            $query->where('occurred_at', '>=', AuditLogEvent::storedTime($filters->rangeStart));
        }

        if ($filters->rangeEnd !== null) {
            $query->where('occurred_at', '<', AuditLogEvent::storedTime($filters->rangeEnd));
        }

        return $query;
    }

    /**
     * One page: at most $limit events after $cursor, and the cursor of the next page when
     * there is one.
     *
     * @return array{events: list<AuditLogEvent>, has_more: bool, next_cursor: string|null}
     */
    public static function page(AuditLogFilters $filters, int $limit, ?string $cursor, bool $newestFirst = true): array
    {
        $query = self::ordered(self::filtered($filters), $newestFirst);
        $after = self::decode($cursor);

        if ($after !== null) {
            self::continueAfter($query, $after, $newestFirst);
        }

        $rows = array_values($query->limit($limit + 1)->get()->all());
        $hasMore = count($rows) > $limit;
        $events = array_slice($rows, 0, $limit);
        $last = $events === [] ? null : $events[count($events) - 1];

        return [
            'events' => $events,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $last !== null ? self::encode($last) : null,
        ];
    }

    /**
     * Every matching event, newest first, read a keyset page at a time — what an export
     * writes, at most $max of them.
     *
     * @return Generator<int, AuditLogEvent>
     */
    public static function each(AuditLogFilters $filters, int $max, int $chunk = 1000): Generator
    {
        $cursor = null;
        $yielded = 0;

        do {
            $page = self::page($filters, min($chunk, $max - $yielded), $cursor);

            foreach ($page['events'] as $event) {
                yield $event;
                $yielded++;
            }

            $cursor = $page['next_cursor'];
        } while ($cursor !== null && $yielded < $max);
    }

    /**
     * Whether $cursor is one this list could have handed out.
     */
    public static function validCursor(string $cursor): bool
    {
        return self::decode($cursor) !== null;
    }

    /**
     * @param  Builder<AuditLogEvent>  $query
     * @return Builder<AuditLogEvent>
     */
    private static function ordered(Builder $query, bool $newestFirst): Builder
    {
        $direction = $newestFirst ? 'desc' : 'asc';

        return $query->orderBy('occurred_at', $direction)->orderBy('id', $direction);
    }

    /**
     * @param  Builder<AuditLogEvent>  $query
     * @param  array{0: string, 1: string}  $after
     */
    private static function continueAfter(Builder $query, array $after, bool $newestFirst): void
    {
        [$time, $id] = $after;
        $beyond = $newestFirst ? '<' : '>';

        $query->where(static fn (Builder $q): Builder => $q
            ->where('occurred_at', $beyond, $time)
            ->orWhere(static fn (Builder $tie): Builder => $tie->where('occurred_at', $time)->where('id', $beyond, $id)));
    }

    private static function encode(AuditLogEvent $event): string
    {
        // Re-formatted rather than taken as read: PostgreSQL drops trailing zeros from a
        // fraction, and the cursor must compare exactly as the column was written.
        $time = AuditLogEvent::storedTime(CarbonImmutable::parse($event->occurred_at, 'UTC'));

        return rtrim(strtr(base64_encode($time.'|'.$event->id), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (! is_string($decoded) || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3})\|([0-9a-z]{26})$/', $decoded, $parts) !== 1) {
            return null;
        }

        return [$parts[1], $parts[2]];
    }
}
