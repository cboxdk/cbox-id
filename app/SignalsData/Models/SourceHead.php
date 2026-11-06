<?php

declare(strict_types=1);

namespace App\SignalsData\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $environment_id
 * @property string $generation
 * @property int $sequence
 * @property int $retained_after
 * @property int $record_count
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $heartbeat_at
 */
final class SourceHead extends Model
{
    protected $table = 'signals_source_heads';

    protected $primaryKey = 'environment_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'retained_after' => 'integer', 'record_count' => 'integer', 'started_at' => 'immutable_datetime', 'heartbeat_at' => 'immutable_datetime'];
    }
}
