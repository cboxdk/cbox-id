<?php

declare(strict_types=1);

namespace App\Models;

use App\Platform\StandingApprovals\StandingApprovals;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An organization owner's standing approval of one machine client's deployments to one
 * environment of an app ({@see StandingApprovals}). ENVIRONMENT-OWNED: it is looked up by the
 * token endpoint of the environment that holds it, and nowhere else.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $organization_id
 * @property string $client_id the machine client whose deployments it approves
 * @property string $app_client_id the app the approval token is issued to
 * @property string $environment_ref the app's environment
 * @property string $environment_kind the app's kind of that environment; never production
 * @property string $granted_by the owner's subject id
 * @property Carbon $granted_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property string|null $revoke_reason
 * @property Carbon|null $last_used_at
 * @property int $uses
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StandingApproval extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'uses' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
