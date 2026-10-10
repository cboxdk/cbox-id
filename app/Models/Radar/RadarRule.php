<?php

declare(strict_types=1);

namespace App\Models\Radar;

use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarRuleScope;
use App\Platform\Radar\RadarConditions;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One of an environment's own Radar rules: when EVERY condition holds, take this action.
 *
 * Rules are evaluated in `position` order and the first one that matches decides — an allow
 * rule placed first is how an administrator carves an exception out of the built-in rules
 * after it. Conditions are structured data checked on save ({@see RadarConditions}); nothing
 * in a rule is ever executed.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $name
 * @property string|null $description
 * @property int $position
 * @property bool $enabled
 * @property RadarRuleScope $applies_to
 * @property RadarAction $action
 * @property list<array{field: string, operator: string, value: string|list<string>}> $conditions
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class RadarRule extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    /** The most rules one environment may have — each is evaluated on every attempt. */
    public const int MAX_RULES = 100;

    protected $table = 'radar_rules';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'enabled' => 'boolean',
            'applies_to' => RadarRuleScope::class,
            'action' => RadarAction::class,
            'conditions' => 'array',
        ];
    }
}
