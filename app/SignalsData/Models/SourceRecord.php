<?php

declare(strict_types=1);

namespace App\SignalsData\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $environment_id
 * @property int $sequence
 * @property string $id
 * @property string $name
 * @property string|null $organization_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property array<string, mixed> $data
 */
final class SourceRecord extends Model
{
    protected $table = 'signals_source_records';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'occurred_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime', 'data' => 'array'];
    }
}
