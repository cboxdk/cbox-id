<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Cbox\Id\Devices\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A per-device circuit breaker on the device's own health columns — the same shape as
 * the webhook and outbound-SCIM breakers, for the same reason.
 *
 * After `failure_threshold` consecutive failures the breaker OPENS and pushes to THAT
 * handset pause for `cooldown_seconds`, after which a single probe is admitted
 * (half-open); success closes it and resets the count, failure re-opens it.
 *
 * Why it matters for push specifically: a phone that is off, out of coverage, or whose
 * token has gone stale can fail slowly. Without a breaker every notification to it pays
 * a fresh connect-plus-read timeout, and a user with one dead handset and one live one
 * makes every approval prompt wait on the dead one first.
 *
 * Nothing is dropped: notifications are still written and still retried, they just wait
 * out the cooldown.
 *
 * WHY THESE WRITE, WHERE THE WEBHOOK BREAKER STAGES
 * -------------------------------------------------
 * The upstream EndpointCircuitBreaker mutates the model and lets the caller save. That
 * is a read-modify-write on a value loaded before a network round trip, and push fans
 * out per device: two workers delivering to the same handset both load
 * `consecutive_failures = 4`, and whichever saves last wins. A success writing 0 can
 * erase a concurrent failure's 5, leaving a dead device permanently un-tripped; a
 * failure writing 5 can erase a concurrent success, tripping a healthy one.
 *
 * So the writes here are atomic and immediate: an absolute update on success, a
 * database-side increment on failure. The extra round trip is on the failure path,
 * never the delivering one.
 */
class DeviceCircuitBreaker
{
    /** True while the breaker is open and its cooldown has not yet elapsed. */
    public function isOpen(Device $device): bool
    {
        return $this->closesAt($device)?->isFuture() ?? false;
    }

    /** True when an attempt is permitted — closed, or a half-open probe. */
    public function shouldAttempt(Device $device): bool
    {
        return ! $this->isOpen($device);
    }

    /** When an open breaker admits its next probe, or null when closed. */
    public function closesAt(Device $device): ?Carbon
    {
        if ($device->circuit_opened_at === null) {
            return null;
        }

        return $device->circuit_opened_at->copy()->addSeconds($this->cooldown());
    }

    /**
     * Absolute values, so this is safe to apply concurrently: two workers both
     * succeeding write the same thing.
     */
    public function recordSuccess(Device $device): void
    {
        $attributes = [
            'consecutive_failures' => 0,
            'last_success_at' => Carbon::now(),
            'last_error' => null,
            'circuit_opened_at' => null,
        ];

        Device::query()->whereKey($device->id)->update($attributes);

        $device->forceFill($attributes);
    }

    public function recordFailure(Device $device, ?string $error = null): void
    {
        $message = $error === null ? null : mb_substr($error, 0, 255);

        // Incremented in the database, not in PHP, so concurrent failures accumulate
        // instead of overwriting one another.
        Device::query()->whereKey($device->id)->update([
            'consecutive_failures' => DB::raw('consecutive_failures + 1'),
            'last_error' => $message,
            'updated_at' => Carbon::now(),
        ]);

        $stored = Device::query()->whereKey($device->id)->value('consecutive_failures');
        $failures = is_numeric($stored) ? (int) $stored : $device->consecutive_failures + 1;

        $device->forceFill(['consecutive_failures' => $failures, 'last_error' => $message]);

        if ($failures < $this->threshold()) {
            return;
        }

        // Only stamp an opening if the breaker is not already open: re-stamping on every
        // failure past the threshold would slide the cooldown forward indefinitely and
        // the half-open probe would never be admitted.
        $opened = Carbon::now();

        Device::query()
            ->whereKey($device->id)
            ->whereNull('circuit_opened_at')
            ->update(['circuit_opened_at' => $opened, 'updated_at' => $opened]);

        $device->forceFill([
            'circuit_opened_at' => $device->circuit_opened_at ?? $opened,
        ]);
    }

    private function threshold(): int
    {
        return max(1, DeviceConfig::int('id-devices.circuit_breaker.failure_threshold', 5));
    }

    private function cooldown(): int
    {
        return max(1, DeviceConfig::int('id-devices.circuit_breaker.cooldown_seconds', 300));
    }
}
