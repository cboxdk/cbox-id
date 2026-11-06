<?php

declare(strict_types=1);

namespace Cbox\Id\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property array<string, mixed> $data
 * @property bool $sold
 */
final class BillingPlan extends Model
{
    protected $table = 'id_billing_plans';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'sold' => 'boolean'];
    }
}
