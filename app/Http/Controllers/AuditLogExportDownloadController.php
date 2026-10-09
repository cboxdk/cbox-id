<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLogs\AuditLogExport;
use App\Platform\AuditLogs\AuditLogExports;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The CSV behind a ready audit-log export, for whoever holds its signed URL
 * ({@see AuditLogExports::downloadUrl()}).
 *
 * The `signed` middleware has already proved the URL was minted here, for this export,
 * and has not expired; the export is environment-owned, so the same URL on another
 * environment's host finds nothing. An export past its own expiry is gone even while a
 * URL for it is still inside its minutes.
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
        abort_unless(AuditLogExports::disk()->exists($path), 404);

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
