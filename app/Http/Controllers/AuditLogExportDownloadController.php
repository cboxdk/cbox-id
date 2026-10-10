<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLogs\AuditLogExport;
use App\Platform\AuditLogs\AuditLogExports;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV behind a ready audit-log export, for whoever holds its signed URL
 * ({@see AuditLogExports::downloadUrl()}).
 *
 * The `signed` middleware has already proved the URL was minted here, for this export,
 * and has not expired; the export is environment-owned, so the same URL on another
 * environment's host finds nothing. An export past its own expiry is gone even while a
 * URL for it is still inside its minutes.
 *
 * THE FILE IS CHECKED BEFORE THE DOWNLOAD STARTS. The CSV is written by a queue worker and
 * streamed by a web process, and on Kubernetes those are different pods with different
 * disks: with the export disk left at `local`, the worker wrote the file into its own pod and
 * the web pod streaming the download had nothing. `readStream()` on a disk that does not
 * throw answers null, so the response was a 200 with an empty `audit-logs-….csv` — an export
 * reported `ready`, handed out, and silently empty. Now a missing file is a 404 and an error
 * in the log that names the disk; `cbox-id:doctor` fails the same configuration up front
 * ("Files are local to one machine").
 */
final class AuditLogExportDownloadController
{
    public function __invoke(string $export): StreamedResponse
    {
        $model = AuditLogExport::query()->whereKey($export)->first();

        abort_if($model === null || ! $model->downloadable() || $model->path === null, 404);

        $name = 'audit-logs-'.($model->organization_id ?? 'environment').'-'.$model->created_at->format('Y-m-d').'.csv';

        $path = $model->path;

        /*
         * NOT HERE IS A 404, never an empty file. The export is written by the queue, and
         * on a disk only that process has — `local`, on a deployment whose worker and web
         * replicas are different pods — this replica cannot see it. Streaming anyway
         * answered 200 with an empty CSV, which reads as "nothing happened in this period"
         * to whoever opens it. `cbox-id:doctor` fails that disk on a scaled-out deployment.
         */
        if (! AuditLogExports::disk()->exists($path)) {
            Log::error('Audit-log export file is missing from the export disk', [
                'export' => $model->id,
                'disk' => config('cbox-id.audit_logs.export_disk'),
                'path' => $path,
                'hint' => 'The export disk must be storage every web and worker process shares; run `php artisan cbox-id:doctor`.',
            ]);

            abort(404);
        }

        return response()->streamDownload(static function () use ($path): void {
            $stream = AuditLogExports::disk()->readStream($path);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $name, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
