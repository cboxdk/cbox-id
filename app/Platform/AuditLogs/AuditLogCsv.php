<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use App\Http\Resources\Environment\Timestamp;
use App\Models\AuditLogs\AuditLogEvent;

/**
 * Audit events as CSV — one shape for the queued export and the Admin Portal's direct
 * download, so a customer's admin gets the same columns whichever way they asked.
 *
 * Every cell is something an app SENT, which makes a spreadsheet the attack surface: a
 * value starting `=`, `+`, `-`, `@` or a control character is a formula to Excel and
 * LibreOffice (CSV injection). Such a cell is written with a leading `'`, the convention
 * both read as "this is text". The hashes in the last columns are the chain's, so the CSV
 * is checkable against the API's copy.
 */
final class AuditLogCsv
{
    /** @var list<string> */
    public const array COLUMNS = [
        'id', 'occurred_at', 'organization_id', 'action',
        'actor_id', 'actor_type', 'actor_name', 'actor_metadata',
        'targets', 'location', 'user_agent', 'metadata',
        'sequence', 'received_at', 'prev_hash', 'hash',
    ];

    /**
     * Write the header and every event to $handle; returns how many rows were written.
     *
     * @param  resource  $handle
     * @param  iterable<AuditLogEvent>  $events
     */
    public static function write($handle, iterable $events): int
    {
        fputcsv($handle, self::COLUMNS, escape: '');
        $count = 0;

        foreach ($events as $event) {
            fputcsv($handle, array_map(self::cell(...), self::row($event)), escape: '');
            $count++;
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    public static function row(AuditLogEvent $event): array
    {
        return [
            $event->id,
            $event->occurredAtIso(),
            $event->organization_id,
            $event->action,
            $event->actor_id,
            $event->actor_type,
            (string) $event->actor_name,
            self::json($event->actor_metadata),
            self::json($event->targets),
            (string) $event->location,
            (string) $event->user_agent,
            self::json($event->metadata),
            (string) $event->sequence,
            (string) Timestamp::of($event->created_at),
            $event->prev_hash,
            $event->hash,
        ];
    }

    /** A cell a spreadsheet will show as text, whatever it starts with. */
    public static function cell(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r\n", $value[0]) ? "'".$value : $value;
    }

    /**
     * @param  array<mixed>|null  $value
     */
    private static function json(?array $value): string
    {
        return $value === null || $value === [] ? '' : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
