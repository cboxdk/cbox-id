<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Sinks;

use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\ValueObjects\AuditExportBatch;
use Cbox\Id\Compliance\ValueObjects\AuditExportRecord;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Writes each batch as newline-delimited JSON (JSONL) to a bundle file per chain on
 * a configured Storage disk — one file `{prefix}/{scope}.jsonl`, appended to. JSONL
 * is append-friendly and streamable, and because each line carries the entry's
 * `sequence`/`prev_hash`/`hash` the bundle can be re-verified as an independent cold
 * archive of the trail.
 *
 * It throws if the disk write fails, so the engine holds its cursor and re-offers
 * the batch — the archive is never silently short a segment.
 */
final class JsonlBundleExportSink implements AuditExportSink
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $prefix = 'compliance/audit',
    ) {}

    public function export(AuditExportBatch $batch): void
    {
        if ($batch->records === []) {
            return;
        }

        $lines = array_map(
            static fn (AuditExportRecord $record): string => json_encode(
                $record->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            $batch->records,
        );

        $path = $this->pathFor($batch->scope);
        $payload = implode("\n", $lines)."\n";

        $ok = $this->disk->append($path, rtrim($payload, "\n"));

        if ($ok === false) {
            throw new \RuntimeException("Failed to append audit export bundle to [{$path}].");
        }
    }

    private function pathFor(string $scope): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $scope) ?? 'scope';

        return trim($this->prefix, '/')."/{$safe}.jsonl";
    }
}
