<?php

declare(strict_types=1);

namespace App\Models\Radar;

use App\Platform\Radar\Enums\RadarList;
use App\Platform\Radar\Enums\RadarListKind;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One entry on an environment's allow or deny list.
 *
 * The value is what an administrator typed (normalised: a lower-case address or domain, an
 * IP or CIDR in canonical form, a device pseudonym) — configuration, kept readable on
 * purpose, and the one place Radar holds an address or an IP as such. An entry may expire.
 *
 * @property string $id
 * @property string $environment_id
 * @property RadarList $list
 * @property RadarListKind $kind
 * @property string $value
 * @property string|null $note
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 */
final class RadarListEntry extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    /** The most entries one environment may hold across both lists. */
    public const int MAX_ENTRIES = 5000;

    protected $table = 'radar_list_entries';

    protected $guarded = [];

    public function active(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'list' => RadarList::class,
            'kind' => RadarListKind::class,
            'expires_at' => 'datetime',
        ];
    }
}
