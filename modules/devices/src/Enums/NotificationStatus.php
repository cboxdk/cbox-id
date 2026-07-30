<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Enums;

/**
 * The lifecycle of a single push. Four of the six states are TERMINAL, and that
 * matters more than it looks: a row that can never be settled is a row the retry sweep
 * re-selects forever, so every path out of delivery must land on one of them rather
 * than simply returning.
 */
enum NotificationStatus: string
{
    /** Written, not yet attempted. The stranded sweep rescues these if the job is lost. */
    case Pending = 'pending';

    /** Attempted and failed transiently; `next_retry_at` says when to try again. */
    case Failed = 'failed';

    /** Accepted by the transport. Terminal. */
    case Delivered = 'delivered';

    /** Retries exhausted, or the device disappeared underneath us. Terminal. */
    case Exhausted = 'exhausted';

    /** Its deadline passed before it could be delivered. Terminal. */
    case Expired = 'expired';

    /** No transport was configured, so it was never going to be sent. Terminal. */
    case Skipped = 'skipped';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Failed => false,
            self::Delivered, self::Exhausted, self::Expired, self::Skipped => true,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Failed => 'Retrying',
            self::Delivered => 'Delivered',
            self::Exhausted => 'Failed',
            self::Expired => 'Expired',
            self::Skipped => 'Not sent',
        };
    }
}
