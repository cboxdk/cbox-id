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
     * Where delivered events are stored, when ClickHouse is not configured below.
     *
     *   none      (default) — events go nowhere; dashboards read the platform's own
     *                         usage counters through the UsageMeter. Nothing grows.
     *   database            — events are written to `id_analytics_events` in the
     *                         app's own database, and the dashboards read them.
     *                         The right answer at low volume, and the only one
     *                         available where there is no column store to point at.
     *
     * A ClickHouse DSN always wins over this: set one and both the sink and the
     * reader switch to it regardless of what is set here.
     *
     * The relational store is a ROW store, so the dashboards' GROUP BY aggregates
     * scan an index range. Growth shows up as dashboard latency rather than wrong
     * numbers, and that latency — not a correctness cliff — is the signal to move to
     * ClickHouse. Retention is enforced by the scheduled `model:prune` in
     * routes/console.php, using `retention_days` below; without pruning the table
     * grows with traffic forever, which is the one way this store can hurt you.
     */
    'store' => (string) env('ID_ANALYTICS_STORE', 'none'),

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
