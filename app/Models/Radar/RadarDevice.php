<?php

declare(strict_types=1);

namespace App\Models\Radar;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * A browser an account has SUCCESSFULLY signed in from — the history "new device" and
 * "impossible travel" are measured against.
 *
 * Only pseudonyms: the account (`subject_hash`), the device cookie (`device_hash`) and the
 * normalised user-agent fingerprint (`fingerprint_hash`). The location is the coarse one of
 * the last sign-in on it — a country and a point rounded to one decimal. Written only after a
 * sign-in succeeds, so a stranger typing an address cannot add a device, or a location, to
 * somebody else's history.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $subject_hash
 * @property string|null $device_hash
 * @property string $fingerprint_hash
 * @property string|null $country
 * @property string|null $latitude
 * @property string|null $longitude
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
final class RadarDevice extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;
    use Prunable;

    /** Devices remembered per account; the least recently used goes first. */
    public const int MAX_PER_SUBJECT = 20;

    public $timestamps = false;

    protected $table = 'radar_devices';

    protected $guarded = [];

    /**
     * Devices not signed in from within the retention window.
     *
     * Pruned across EVERY environment: `model:prune` runs with none in context, and the
     * environment scope would otherwise answer `1 = 0` and keep everything for ever.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = self::retentionDays();
        $query = self::query()->withoutGlobalScopes();

        return $days === null
            ? $query->whereRaw('1 = 0')
            : $query->where('last_seen_at', '<', Carbon::now()->subDays($days));
    }

    public static function retentionDays(): ?int
    {
        $configured = config('cbox-id.radar.device_retention_days', 180);

        if ($configured === null || $configured === '' || $configured === false) {
            return null;
        }

        return is_numeric($configured) ? max(1, (int) $configured) : 180;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
