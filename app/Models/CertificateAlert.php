<?php

declare(strict_types=1);

namespace App\Models;

use App\Platform\Sso\CertificateExpiryAlerts;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One SAML certificate expiry alert that went out — so the next day's scan does not send it
 * again ({@see CertificateExpiryAlerts}). An APP table, environment-owned like the
 * connections it is about.
 *
 * @property string $id
 * @property string $environment_id
 * @property string|null $organization_id
 * @property string $connection_id
 * @property string $fingerprint
 * @property int $threshold_days
 * @property Carbon $not_after
 * @property Carbon $notified_at
 */
final class CertificateAlert extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'sso_certificate_alerts';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold_days' => 'integer',
            'not_after' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
