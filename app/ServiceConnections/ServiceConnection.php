<?php

declare(strict_types=1);

namespace App\ServiceConnections;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property string $consumer_client_id
 * @property string $service
 * @property string $service_client_id
 * @property string $audience
 * @property list<string> $scopes
 * @property string $created_by
 * @property Carbon|null $revoked_at
 */
class ServiceConnection extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'revoked_at' => 'datetime'];
    }
}
