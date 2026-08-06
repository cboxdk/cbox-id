<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Models;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A persisted, elevated risk assessment — the review trail behind the console.
 * Only outcomes at or above `Flag` are recorded (an allow isn't worth a row), so
 * the table stays a signal, not a log of every request.
 *
 * @property string $environment_id
 * @property string $action
 * @property string $outcome
 * @property float $score
 * @property string|null $ip
 * @property string|null $email
 * @property array<int, string> $reasons
 * @property Carbon $created_at
 */
class RiskEvent extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;

    public const UPDATED_AT = null;

    protected $table = 'risk_plus_events';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'reasons' => 'array',
        ];
    }
}
