<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Models;

use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A user's enrolled handset.
 *
 * Hard-scoped to its environment, so one tenant's device list is never visible or
 * writable from another — and with no environment in context the scope resolves to no
 * rows rather than all rows.
 *
 * The push token is sealed at rest and opened only inside a delivery attempt. It is
 * exposed nowhere: not in the console, not in the API's device list, not in a queue
 * payload. Anyone holding it plus the project's FCM credentials can push to this
 * handset, which makes it a capability rather than an identifier.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $subject_id
 * @property string $install_id
 * @property DevicePlatform $platform
 * @property string $name
 * @property DeviceStatus $status
 * @property string|null $token_encrypted
 * @property string|null $dpop_jkt
 * @property string|null $app_version
 * @property string|null $os_version
 * @property string|null $model
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $token_rotated_at
 * @property int $last_sequence
 * @property int $consecutive_failures
 * @property Carbon|null $circuit_opened_at
 * @property Carbon|null $last_success_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Device extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;

    protected $table = 'id_devices';

    protected $guarded = [];

    /**
     * The additional authenticated data the push token is sealed under. Binding it to
     * this row's id means a ciphertext lifted from one device cannot be replayed
     * against another — decryption fails outright on a context mismatch.
     */
    public function secretContext(): string
    {
        return 'cbox-id:device-token:'.$this->id;
    }

    /**
     * Eligible to be pushed to: the user has not removed it and the transport has not
     * told us its token is dead.
     */
    public function isNotifiable(): bool
    {
        return $this->status === DeviceStatus::Active
            && is_string($this->token_encrypted)
            && $this->token_encrypted !== '';
    }

    /**
     * @param  Builder<Device>  $query
     * @return Builder<Device>
     */
    public function scopeNotifiable(Builder $query): Builder
    {
        return $query->where('status', DeviceStatus::Active->value)
            ->whereNotNull('token_encrypted');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'status' => DeviceStatus::class,
            'last_seen_at' => 'datetime',
            'token_rotated_at' => 'datetime',
            'circuit_opened_at' => 'datetime',
            'last_success_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_sequence' => 'integer',
        ];
    }
}
