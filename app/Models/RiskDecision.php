<?php

declare(strict_types=1);

namespace App\Models;

use App\Platform\RiskGuard;
use App\Platform\RiskTrail;
use Cbox\Risk\Enums\Outcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * One recorded risk assessment — the unit of evidence a threshold is set from.
 *
 * Written by {@see RiskTrail} from the single choke point
 * {@see RiskGuard::assess()}. Append-only in practice: nothing updates a
 * row, so the model carries no `updated_at`.
 *
 * NOT environment-owned on purpose. Every other credential-bearing app table
 * (`admin_portal_links`, `invitation_role_grants`) implements `EnvironmentOwned`,
 * because a leaked row there is a cross-tenant capability. This one is pre-auth
 * security telemetry with no capability in it, and its ONLY consumer is a
 * deployment-wide tuning query run from a console session that has no environment in
 * context — where the hard outer scope would emit `1 = 0` and report an empty corpus,
 * reproducing the exact failure this table exists to fix. The environment is kept as
 * an ordinary column so a query can still narrow to one.
 *
 * @property string $id
 * @property string|null $environment_id
 * @property string $action
 * @property string $mode
 * @property Outcome $outcome
 * @property float $score
 * @property list<string> $reasons
 * @property array<string, float> $signals
 * @property string $ip_hash
 * @property string|null $email_hash
 * @property string|null $email_domain
 * @property Carbon $assessed_at
 */
final class RiskDecision extends Model
{
    use HasUlids;
    use Prunable;

    public const CREATED_AT = 'assessed_at';

    public const UPDATED_AT = null;

    protected $table = 'risk_decisions';

    protected $guarded = [];

    /**
     * Rows past the configured retention window.
     *
     * Swept by Laravel's own `model:prune` (scheduled in `routes/console.php`) rather
     * than by the framework's `cbox-id:prune`: that command's table list is the
     * `Cbox\Id\Maintenance\Enums\PrunableTable` enum inside the package, which an
     * application cannot extend. `model:prune` deletes in bounded chunks and is
     * environment-blind, which is the same posture the package sweep documents.
     *
     * A `null` retention (an explicit `null`/empty in config) turns the sweep OFF —
     * matching `Cbox\Id\Maintenance\Pruner::retentionDays()` — for a deployment whose
     * compliance regime wants the trail kept indefinitely.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = self::retentionDays();

        if ($days === null) {
            return self::query()->whereRaw('1 = 0');
        }

        return self::query()->where('assessed_at', '<', Carbon::now()->subDays($days));
    }

    /**
     * Days a decision is kept, or null when retention is disabled.
     *
     * 90 days by default. Tuning a threshold means comparing a week against the weeks
     * around it, so a 30-day window (what the framework's fastest-growing tables get)
     * is too short to see a seasonal false-positive spike for what it is; and this
     * table takes one small row per sign-in attempt, not one per request.
     */
    public static function retentionDays(): ?int
    {
        $configured = config('cbox-id.risk_trail.retention_days', 90);

        if ($configured === null || $configured === '' || $configured === false) {
            return null;
        }

        return is_numeric($configured) ? max(0, (int) $configured) : 90;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => Outcome::class,
            'score' => 'float',
            'reasons' => 'array',
            'signals' => 'array',
            'assessed_at' => 'datetime',
        ];
    }
}
