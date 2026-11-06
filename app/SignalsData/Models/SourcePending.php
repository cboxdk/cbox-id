<?php

declare(strict_types=1);

namespace App\SignalsData\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $environment_id
 * @property string $id
 * @property string $name
 * @property string|null $organization_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property array<string, mixed> $data
 * @property list<string> $subject_hashes
 */
final class SourcePending extends Model
{
    protected $table = 'signals_source_pending';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['subject_hashes' => 'array', 'occurred_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime', 'data' => 'array'];
    }
}
