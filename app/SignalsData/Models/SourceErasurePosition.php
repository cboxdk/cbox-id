<?php

declare(strict_types=1);

namespace App\SignalsData\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $sequence
 * @property array<string, mixed> $record
 */
final class SourceErasurePosition extends Model
{
    protected $table = 'signals_source_erasure_positions';

    public $incrementing = false;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'record' => 'array'];
    }
}
