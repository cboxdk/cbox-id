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
 *
 * ON OBJECT STORAGE, ONE OBJECT PER BATCH. An object cannot be appended to: Laravel's
 * `append()` on an S3-compatible disk downloads the whole object and uploads it again
 * with the batch on the end. Against one ever-growing `{scope}.jsonl` that is a transfer
 * the size of the whole archive for every 500 entries. With `$segmented` (the provider
 * sets it for any disk that is not `local`), each batch is its own object,
 * `{prefix}/{environment}/{scope}/{from}.jsonl`, named by its first sequence and
 * zero-padded so the bucket lists the objects in chain order; concatenated in that order
 * they are the bundle. A batch the engine re-offers — its cursor did not move — starts at
 * the same sequence, so the retry overwrites the object it wrote the first time (with as
 * many entries or more) instead of adding a second copy of them.
 */
class JsonlBundleExportSink implements AuditExportSink
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $prefix = 'compliance/audit',
        private readonly bool $segmented = false,
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

        $payload = implode("\n", $lines)."\n";

        if ($this->segmented) {
            $path = $this->segmentPathFor($batch);
            $ok = $this->disk->put($path, $payload);
        } else {
            $path = $this->pathFor($batch->environmentId, $batch->scope);
            $ok = $this->disk->append($path, rtrim($payload, "\n"));
        }

        if ($ok === false) {
            throw new \RuntimeException("Failed to write audit export bundle to [{$path}].");
        }
    }

    /**
     * One file per (environment, scope).
     *
     * Keyed on the scope alone, every environment's system trail appended to the same
     * `__system__.jsonl`: two independent hash chains interleaved in one object, with
     * colliding sequence numbers and `prev_hash` values that do not link — which
     * destroys the single property the bundle exists for, that it can be re-verified as
     * a standalone cold archive. Handing that to one customer as their audit evidence
     * also hands them another customer's operator-level entries.
     *
     * The database side of this collision was already fixed (the export cursor is keyed
     * per environment); the output path was not fixed with it, and until the export
     * command started running at all, nothing had ever produced the collision.
     */
    private function pathFor(string $environmentId, string $scope): string
    {
        $safeScope = preg_replace('/[^A-Za-z0-9_.-]/', '_', $scope) ?? 'scope';
        $safeEnvironment = preg_replace('/[^A-Za-z0-9_.-]/', '_', $environmentId) ?? 'environment';

        return trim($this->prefix, '/')."/{$safeEnvironment}/{$safeScope}.jsonl";
    }

    /**
     * One object per batch, under the path the appended bundle would have had (without
     * its `.jsonl`), named by the first sequence it holds.
     */
    private function segmentPathFor(AuditExportBatch $batch): string
    {
        $directory = substr($this->pathFor($batch->environmentId, $batch->scope), 0, -strlen('.jsonl'));

        return sprintf('%s/%020d.jsonl', $directory, $batch->fromSequence);
    }

    /** A real destination. */
    public function isInert(): bool
    {
        return false;
    }
}
