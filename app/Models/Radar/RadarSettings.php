<?php

declare(strict_types=1);

namespace App\Models\Radar;

use App\Platform\Radar\RadarPolicy;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An environment's Radar choices, when it has made any: its mode and its tuning of the
 * built-in rules. Read through {@see RadarPolicy}, which supplies every default.
 *
 * @property string $id
 * @property string $environment_id
 * @property string|null $mode
 * @property array<string, array<string, mixed>>|null $builtin_rules
 * @property Carbon|null $updated_at
 */
final class RadarSettings extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'radar_settings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'builtin_rules' => 'array',
        ];
    }
}
