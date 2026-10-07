<?php

declare(strict_types=1);

namespace App\Platform\Health;

use Cbox\Id\Kernel\Events\Contracts\RelayBacklog;
use Cbox\LaravelHealth\Checks\BaseCheck;
use Cbox\LaravelHealth\DataTransferObjects\CheckResult;

/**
 * `event_relay` on `/health/status`: red when the oldest undelivered domain event is too old.
 *
 * The scheduler beating is necessary, not sufficient: the relay can run every minute and
 * still fall behind, or fail on every pass. Age is the signal rather than depth — a large
 * backlog that is moving is fine, a small one that is ageing is not ({@see RelayBacklog}).
 *
 * The metadata is counts and timestamps only, never event payloads.
 */
class EventRelayHealthCheck extends BaseCheck
{
    public function __construct(private readonly RelayBacklog $backlog) {}

    public function name(): string
    {
        return 'event_relay';
    }

    public function run(): CheckResult
    {
        $depth = $this->backlog->depth();
        $max = self::maxLagSeconds();
        $age = $depth->oldestAgeSeconds();
        $metadata = [...$depth->toArray(), 'max_lag_seconds' => $max];

        if ($age > $max) {
            return CheckResult::critical(
                $this->name(),
                "The oldest undelivered event has waited {$age}s (limit {$max}s); {$depth->total()} waiting. "
                .'Webhooks and outbound sync are behind.',
                $metadata,
            );
        }

        return CheckResult::ok($this->name(), "{$depth->total()} events waiting, oldest {$age}s", $metadata);
    }

    public static function maxLagSeconds(): int
    {
        $configured = config('health.checks_config.event_relay.max_lag_seconds', 300);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 300;
    }
}
