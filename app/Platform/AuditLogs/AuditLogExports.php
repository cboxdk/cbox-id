<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use App\Http\Resources\Environment\Timestamp;
use App\Models\AuditLogs\AuditLogExport;
use App\Platform\AuditLogs\Jobs\GenerateAuditLogExport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * CSV exports of audit events: asked for now, written on the queue, fetched through a
 * signed URL that lives minutes.
 *
 * Asynchronous because an organization's year of events is not something a request
 * should hold a worker for. The file lands on a private disk (`cbox-id.audit_logs.
 * export_disk`) and is reachable only through {@see self::downloadUrl()}: signed, so it
 * cannot be guessed or altered, and short, so a URL pasted into a ticket is dead by the
 * time anybody reads the ticket. The export itself lives `export_ttl_hours`, then the
 * prune deletes the file.
 */
final class AuditLogExports
{
    /** The most rows one export holds. A request for more is narrowed by its range. */
    public const int MAX_ROWS = 1_000_000;

    /** How long a download URL works. */
    public const int URL_MINUTES = 15;

    /**
     * Ask for an export of what $filters select; the job is queued once the transaction
     * this runs in commits, so it never looks for a row that is not there yet.
     */
    public function request(AuditLogFilters $filters, string $requestedBy): AuditLogExport
    {
        $export = new AuditLogExport;
        $export->forceFill([
            'organization_id' => $filters->organizationId,
            'filters' => $filters->toArray(),
            'state' => AuditLogExport::PENDING,
            'requested_by' => $requestedBy,
        ])->save();

        GenerateAuditLogExport::dispatch($export->id)->afterCommit();

        return $export;
    }

    /** A signed URL the file downloads from, or null when there is no file to download. */
    public function downloadUrl(AuditLogExport $export): ?string
    {
        if (! $export->downloadable()) {
            return null;
        }

        $expires = Carbon::now()->addMinutes(self::URL_MINUTES);

        if ($export->expires_at !== null && $export->expires_at->lessThan($expires)) {
            $expires = $export->expires_at;
        }

        return URL::temporarySignedRoute('audit-logs.exports.download', $expires, ['export' => $export->id]);
    }

    /**
     * An export as every door presents it — `AuditLogExport` in the spec.
     *
     * @return array<string, mixed>
     */
    public function present(AuditLogExport $export): array
    {
        return [
            'id' => $export->id,
            'organization_id' => $export->organization_id,
            'state' => $export->visibleState(),
            'filters' => $export->filters,
            'row_count' => $export->row_count,
            'url' => $this->downloadUrl($export),
            'created_at' => Timestamp::of($export->created_at),
            'completed_at' => Timestamp::of($export->completed_at),
            'expires_at' => Timestamp::of($export->expires_at),
        ];
    }

    /**
     * The contract, not the adapter: telemetry wraps every disk in an instrumented
     * decorator, and only the contract is what both are.
     */
    public static function disk(): Filesystem
    {
        $name = config('cbox-id.audit_logs.export_disk', 'local');

        return Storage::disk(is_string($name) && $name !== '' ? $name : 'local');
    }

    public static function ttlHours(): int
    {
        $hours = config('cbox-id.audit_logs.export_ttl_hours', 72);

        return is_int($hours) && $hours > 0 ? $hours : 72;
    }
}
