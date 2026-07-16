<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Sinks;

use Cbox\Id\Analytics\Contracts\ReportSink;

/**
 * The default sink: it writes nothing. With it bound the plugin is inert — events
 * stream into a no-op, so installing without configuring ClickHouse is safe and
 * costs nothing on the hot delivery path. Configure a ClickHouse DSN to swap in a
 * real {@see ClickHouseReportSink}.
 */
final class NullReportSink implements ReportSink
{
    public function write(array $records): void
    {
        // Intentionally empty: analytics is off until a real sink is wired.
    }
}
