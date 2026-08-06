<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The relational event store for analytics — the alternative to ClickHouse for a
 * deployment that has no column store, which now includes the hosted one (Laravel
 * Cloud offers no ClickHouse service).
 *
 * `event_id` is the PRIMARY KEY, not a surrogate id with a unique index beside it.
 * Outbox delivery is at-least-once, so the same event can arrive twice; making the
 * natural key the primary key is what lets the sink write with `insertOrIgnore` and
 * get exactly-once semantics from the engine rather than from a read-then-write race.
 * (ClickHouse gets the same property from a ReplacingMergeTree keyed on event_id.)
 *
 * `occurred_on` is stored ALONGSIDE `occurred_at`, deliberately denormalised. The
 * per-day chart groups by day, and there is no date-truncation expression that is
 * portable across MySQL, PostgreSQL and SQLite — MySQL has DATE(), PostgreSQL wants
 * a cast, SQLite's CAST to DATE is not a real type. A plain column groups and indexes
 * on every engine and costs three bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_analytics_events', function (Blueprint $table): void {
            $table->string('event_id', 64)->primary();
            $table->string('type', 191);
            // Empty string rather than null for absent metric/tenancy, matching
            // ReportRecord::toRow() and the ClickHouse schema's non-nullable columns,
            // so both stores answer a query the same way.
            $table->string('metric', 191);
            $table->string('organization_id', 26);
            $table->string('environment_id', 26);
            $table->timestamp('occurred_at');
            $table->date('occurred_on');
            $table->string('payload_digest', 64);
            $table->timestamp('ingested_at');

            // The chart: one metric, one environment, a date range grouped by day.
            $table->index(['environment_id', 'metric', 'occurred_on'], 'id_analytics_env_metric_day_idx');
            // The snapshot and the top-organizations query.
            $table->index(['environment_id', 'occurred_at'], 'id_analytics_env_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_analytics_events');
    }
};
