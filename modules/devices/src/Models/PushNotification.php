<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Models;

use Cbox\Id\Devices\Casts\PushPayloadCast;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Enums\NotificationStatus;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Cbox\Id\Kernel\Tenancy\Concerns\BelongsToEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempted push to one device — the durable record the retry sweep works from.
 *
 * @property string $id
 * @property string $environment_id
 * @property string $device_id
 * @property int $sequence
 * @property NotificationKind $kind
 * @property NotificationStatus $status
 * @property PushPayload $payload
 * @property int $attempt
 * @property int|null $response_code
 * @property string|null $last_error
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PushNotification extends Model implements EnvironmentOwned
{
    use BelongsToEnvironment;
    use HasUlids;
    use MassPrunable;

    protected $table = 'id_device_notifications';

    protected $guarded = [];

    protected $attributes = [
        'payload' => '{}',
    ];

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    /**
     * Delivery history worth keeping for the console, and no longer.
     *
     * This table grows with TRAFFIC — one row per enrolled device per alerted event —
     * rather than with tenants, so without a sweep it grows forever.
     *
     * Only TERMINAL rows are pruned. A Pending or Failed row is still live work that the
     * retry sweep owns, and deleting it would drop the notification silently rather than
     * settling it; that is the retry sweep's job, via the orphan and expiry passes.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = max(1, DeviceConfig::int('id-devices.retention_days', 30));

        return static::query()
            ->whereIn('status', [
                NotificationStatus::Delivered->value,
                NotificationStatus::Exhausted->value,
                NotificationStatus::Expired->value,
                NotificationStatus::Skipped->value,
            ])
            ->where('created_at', '<=', Carbon::now()->subDays($days));
    }

    /**
     * Settle on a terminal state and stop the retry sweep from ever selecting this row
     * again. Every exit from a delivery attempt goes through here rather than simply
     * returning — a row left Pending with no job behind it is unsendable, unprunable
     * and re-selected on every sweep from then on.
     */
    public function settle(NotificationStatus $status, ?string $error = null, ?int $code = null): void
    {
        $this->status = $status;
        $this->next_retry_at = null;

        if ($status === NotificationStatus::Delivered) {
            $this->delivered_at = Carbon::now();
        }

        if ($error !== null) {
            $this->last_error = mb_substr($error, 0, 255);
        }

        if ($code !== null) {
            $this->response_code = $code;
        }

        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => NotificationKind::class,
            'status' => NotificationStatus::class,
            'payload' => PushPayloadCast::class,
            'sequence' => 'integer',
            'attempt' => 'integer',
            'response_code' => 'integer',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
