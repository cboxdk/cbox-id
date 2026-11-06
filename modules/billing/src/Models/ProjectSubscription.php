<?php

declare(strict_types=1);

namespace Cbox\Id\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $project_id
 * @property string $organization_id
 * @property string $plan
 * @property string $status
 * @property array<string, mixed> $data
 * @property CarbonImmutable|null $remote_updated_at
 */
final class ProjectSubscription extends Model
{
    protected $table = 'id_billing_subscriptions';

    protected $primaryKey = 'project_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'remote_updated_at' => 'immutable_datetime'];
    }
}
