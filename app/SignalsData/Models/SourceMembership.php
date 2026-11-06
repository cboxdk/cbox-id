<?php

declare(strict_types=1);

namespace App\SignalsData\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $assertion_id
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_until
 */
final class SourceMembership extends Model
{
    protected $table = 'signals_source_memberships';

    public $incrementing = false;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['valid_from' => 'immutable_datetime', 'valid_until' => 'immutable_datetime'];
    }
}
