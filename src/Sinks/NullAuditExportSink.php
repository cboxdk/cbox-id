<?php

declare(strict_types=1);

namespace Cbox\Id\Compliance\Sinks;

use Cbox\Id\Compliance\Contracts\AuditExportSink;
use Cbox\Id\Compliance\ValueObjects\AuditExportBatch;

/**
 * The default sink: it exports nothing. With it bound the plugin is inert — the
 * export engine finds no destination and the cursor never advances, so installing
 * without configuring a sink is safe and costs nothing. Configure a `jsonl` or
 * `http` sink (via `compliance.export.sink`) to ship the trail somewhere real.
 */
class NullAuditExportSink implements AuditExportSink
{
    public function export(AuditExportBatch $batch): void
    {
        // Intentionally empty: export is off until a real sink is wired.
    }
}
