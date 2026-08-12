<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Models;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An enrolment code that has been spent.
 *
 * The code itself is never stored. It is a short-lived signed JWT, verifiable from the
 * platform's own keys, so a copy would add a credential to the database without adding
 * a check. What is stored is its `jti` — the one property a signature cannot express,
 * since a signature is infinitely replayable and a code photographed off a screen must
 * work exactly once.
 *
 * Hard-scoped to its environment like every other row here, so one tenant can neither
 * see nor spend another's codes.
 *
 * @property string $jti
 * @property string $environment_id
 * @property string $subject_id
 * @property string|null $install_id
 * @property Carbon $consumed_at
 * @property Carbon $expires_at
 */
class EnrolmentCode extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use MassPrunable;

    protected $table = 'id_enrolment_codes';

    protected $primaryKey = 'jti';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Rows whose code could no longer be accepted anyway.
     *
     * A code lives two minutes, and this row exists only to refuse a replay of it. An
     * hour past expiry is long enough to be worth reading during an incident and short
     * enough that nobody has to think about it — the same window LoginTicket settled on
     * for the same reason.
     *
     * WITHOUT THE ENVIRONMENT SCOPE, DELIBERATELY. EnvironmentScope is deny-by-default:
     * with no environment in context it appends `1 = 0`, and a scheduled sweep has no
     * context. Left scoped, this would delete nothing, silently, for the life of the
     * deployment — and the one table that grows with every enrolment would grow without
     * bound while appearing to be swept.
     *
     * The escape is safe HERE because the sweep is a maintenance operation over every
     * environment by definition, and because it can only ever delete rows already past
     * their own expiry. It is not a licence anywhere a tenant's data is read.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->withoutGlobalScopes()
            ->where('expires_at', '<', Carbon::now()->subHour());
    }
}
