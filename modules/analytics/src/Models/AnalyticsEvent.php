<?php

declare(strict_types=1);

namespace Cbox\Id\Analytics\Models;

use Cbox\Id\Analytics\Readers\DatabaseReportReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One row per delivered domain event, in the relational analytics store.
 *
 * DELIBERATELY NOT `BelongsToEnvironment`. That trait stamps the CURRENT
 * environment on write and scopes reads to it — correct for a model an operator
 * edits, wrong here. These rows are written by a queued listener draining the
 * outbox, where the acting context is not necessarily the environment the event
 * belongs to; the record carries its own `environment_id` from the event itself,
 * and {@see DatabaseReportReader} filters on it
 * explicitly — the same thing the ClickHouse reader does, so both stores enforce
 * the boundary the same way.
 *
 * `MassPrunable` because retention here is a bulk delete with no per-row work, and
 * the table is the one part of this app that grows with traffic rather than with
 * tenants. Swept by the scheduled `model:prune` in routes/console.php.
 *
 * @property string $event_id
 * @property string $type
 * @property string $metric
 * @property string $organization_id
 * @property string $environment_id
 * @property Carbon $occurred_at
 * @property Carbon $occurred_on
 * @property string $payload_digest
 * @property Carbon $ingested_at
 */
class AnalyticsEvent extends Model
{
    use MassPrunable;

    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'id_analytics_events';

    protected $primaryKey = 'event_id';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'occurred_on' => 'date',
            'ingested_at' => 'datetime',
        ];
    }

    /**
     * Rows older than the configured retention window.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $configured = config('id-analytics.retention_days', 365);
        $days = is_numeric($configured) ? (int) $configured : 365;

        return static::query()->where('occurred_at', '<', Carbon::now()->subDays(max(1, $days)));
    }
}
