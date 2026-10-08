<?php

declare(strict_types=1);

use App\Platform\Queues\WorkerBounds;
use App\Platform\Queues\WorkerProfile;
use Cbox\LaravelQueueAutoscale\Configuration\InvalidConfigurationException;

/**
 * THE PER-GROUP WORKER BOUNDS ARE A SETTING, AND AN HONEST ONE.
 *
 * Every queue this application dispatches to sits in one worker group, so the group's
 * `max` — not the host-wide cap — is the number that decides how many workers run. These
 * pin that the bounds default to what the profile always said, move when asked, and refuse
 * a pair that could not mean what it says instead of clamping it into a silent no-op.
 */
it('defaults to the profile\'s own bounds when nothing is set', function (mixed $unset): void {
    $bounds = WorkerBounds::fromEnvironment($unset, $unset, 2);

    expect($bounds->min)->toBe(WorkerProfile::MIN_WORKERS)
        ->and($bounds->max)->toBe(WorkerProfile::MAX_WORKERS)
        ->and($bounds->overrides())->toBe(['workers' => ['min' => 1, 'max' => 2]]);
})->with([null, '', '  ']);

it('takes the bounds an environment asks for, within the total cap', function (): void {
    $bounds = WorkerBounds::fromEnvironment('2', ' 6 ', 6);

    expect($bounds->overrides())->toBe(['workers' => ['min' => 2, 'max' => 6]]);
});

it('allows any ceiling when the total cap is unbounded', function (): void {
    // The package reads a cap of zero or below as "no cap"; the bounds read it the same way.
    expect(WorkerBounds::fromEnvironment(1, 12, 0)->max)->toBe(12);
});

it('refuses a pair that cannot mean what it says', function (mixed $min, mixed $max, int $cap, string $message): void {
    expect(fn (): WorkerBounds => WorkerBounds::fromEnvironment($min, $max, $cap))
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'max below min' => ['3', '2', 4, 'QUEUE_AUTOSCALE_WORKERS_MAX (2) must be at least QUEUE_AUTOSCALE_WORKERS_MIN (3)'],
    'max above the total cap' => [null, '4', 2, 'QUEUE_AUTOSCALE_WORKERS_MAX (4) is above QUEUE_AUTOSCALE_MAX_TOTAL_WORKERS (2)'],
    'a floor of zero' => ['0', null, 2, 'QUEUE_AUTOSCALE_WORKERS_MIN must be at least 1'],
    'a negative floor' => ['-1', null, 2, 'QUEUE_AUTOSCALE_WORKERS_MIN must be at least 1'],
    'a word' => [null, 'four', 4, 'QUEUE_AUTOSCALE_WORKERS_MAX must be a whole number'],
    'a fraction' => ['1.5', null, 2, 'QUEUE_AUTOSCALE_WORKERS_MIN must be a whole number'],
    'a boolean' => [true, null, 2, 'QUEUE_AUTOSCALE_WORKERS_MIN must be a whole number'],
]);
