<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Models;

use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
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
}
