<?php

declare(strict_types=1);

return [

    /*
     * Master switch for the console surface. The Analytics area lights up when a
     * real ReportSink is bound (i.e. ClickHouse is configured) OR this is true —
     * so a self-hosted deployment reading usage from Postgres can still show the
     * dashboards without ClickHouse by flipping this on.
     */
    'enabled' => (bool) env('ID_ANALYTICS_ENABLED', false),

    /*
     * ClickHouse is the optional column-store sink for high-volume event analytics.
     * Leave `dsn` empty (the default) and the plugin stays ClickHouse-free: events
     * go to the inert NullReportSink and dashboards read usage counters from the
     * platform's own Postgres via the UsageMeter. Set `dsn` to a ClickHouse HTTP
     * endpoint (e.g. `http://clickhouse:8123`) to switch the sink + reader over.
     */
    'clickhouse' => [
        'dsn' => (string) env('ID_ANALYTICS_CLICKHOUSE_DSN', ''),
        'database' => (string) env('ID_ANALYTICS_CLICKHOUSE_DATABASE', 'default'),
        'user' => (string) env('ID_ANALYTICS_CLICKHOUSE_USER', 'default'),
        'password' => (string) env('ID_ANALYTICS_CLICKHOUSE_PASSWORD', ''),
        'table' => (string) env('ID_ANALYTICS_CLICKHOUSE_TABLE', 'id_analytics_events'),
        'timeout' => (int) env('ID_ANALYTICS_CLICKHOUSE_TIMEOUT', 5),
    ],

    /*
     * How long the ClickHouse event table retains rows. Applied by the install
     * command as a TTL on the table; the Postgres UsageMeter has its own retention.
     */
    'retention_days' => (int) env('ID_ANALYTICS_RETENTION_DAYS', 365),

    /*
     * Default look-back window (in days) for the console charts.
     */
    'chart' => [
        'window_days' => (int) env('ID_ANALYTICS_CHART_WINDOW_DAYS', 30),
    ],
];
