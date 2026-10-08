<?php

declare(strict_types=1);

namespace App\Platform\Queues;

use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;

/**
 * How few and how many workers each of this application's worker groups may run.
 *
 * WHY THIS IS A SETTING AND NOT A CONSTANT. The bounds used to live in
 * {@see WorkerProfile} as literals, and there are two ceilings: this per-group `max` and
 * the host-wide `limits.max_total_workers`. The second was already an environment
 * variable, the first was not — so an operator who gave the queue pod more memory and
 * raised `QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS` got exactly the two workers they had before,
 * because every queue this application dispatches to sits in ONE group and that group
 * still stopped at two. Raising a knob that does nothing is worse than having no knob.
 *
 * Applied the package's own way: as each group's `overrides` (`workers.min`,
 * `workers.max`), deep-merged over {@see WorkerProfile} — so every other number in the
 * profile stays where it is documented, and the defaults here are the profile's own.
 *
 * VALIDATED WHEN THE CONFIGURATION LOADS, not when the manager first sizes a pool. The
 * package rejects `max < min` too, but only once the manager (or the health check) reads
 * the groups; a per-group `max` above the host-wide cap it never rejects at all, it just
 * clamps it — which is the silent no-op this class exists to end. A bound that cannot
 * mean what it says is a deployment error, and the place to hear about it is the
 * rollout, in a sentence that names the variable to fix.
 */
final readonly class WorkerBounds
{
    private function __construct(
        public int $min,
        public int $max,
    ) {}

    /**
     * The bounds an environment asked for, checked against the host-wide cap.
     *
     * @param  mixed  $min  `QUEUE_AUTOSCALE_WORKERS_MIN`; unset or blank keeps {@see WorkerProfile::MIN_WORKERS}.
     * @param  mixed  $max  `QUEUE_AUTOSCALE_WORKERS_MAX`; unset or blank keeps {@see WorkerProfile::MAX_WORKERS}.
     * @param  int  $totalCap  `limits.max_total_workers` as configured; zero or below means
     *                         unbounded, which is how the package reads it too.
     *
     * @throws InvalidConfigurationException when the bounds are not whole numbers, the floor
     *                                       is below one, the ceiling is below the floor, or
     *                                       the ceiling is above the host-wide cap.
     */
    public static function fromEnvironment(mixed $min, mixed $max, int $totalCap): self
    {
        $floor = self::whole('QUEUE_AUTOSCALE_WORKERS_MIN', $min, WorkerProfile::MIN_WORKERS);
        $ceiling = self::whole('QUEUE_AUTOSCALE_WORKERS_MAX', $max, WorkerProfile::MAX_WORKERS);

        // Never zero, for the reason the profile gives: back-channel logout and webhooks are
        // work nobody is watching for, and a scale-from-zero cold start on every sign-out is
        // latency for nothing. The package would accept 0; this application does not.
        if ($floor < 1) {
            throw new InvalidConfigurationException(
                "QUEUE_AUTOSCALE_WORKERS_MIN must be at least 1, got {$floor}: the queue must always have a worker on it."
            );
        }

        if ($ceiling < $floor) {
            throw new InvalidConfigurationException(
                "QUEUE_AUTOSCALE_WORKERS_MAX ({$ceiling}) must be at least QUEUE_AUTOSCALE_WORKERS_MIN ({$floor})."
            );
        }

        if ($totalCap > 0 && $ceiling > $totalCap) {
            throw new InvalidConfigurationException(
                "QUEUE_AUTOSCALE_WORKERS_MAX ({$ceiling}) is above QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS ({$totalCap}), "
                .'so the extra workers could never start. Raise the total cap with it, or lower the group maximum.'
            );
        }

        return new self($floor, $ceiling);
    }

    /**
     * The `overrides` block a worker group carries, in the package's own shape.
     *
     * @return array{workers: array{min: int, max: int}}
     */
    public function overrides(): array
    {
        return ['workers' => ['min' => $this->min, 'max' => $this->max]];
    }

    /**
     * An environment value as a whole number, or the default when it was not set.
     *
     * Strict on purpose: `(int) 'four'` is 0 and `(int) '2.5'` is 2, and either would turn
     * a typo into a worker count nobody chose.
     */
    private static function whole(string $variable, mixed $value, int $default): int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\s*-?\d+\s*$/', $value) === 1) {
            return (int) trim($value);
        }

        throw new InvalidConfigurationException(
            "{$variable} must be a whole number, got ".var_export($value, true).'.'
        );
    }
}
